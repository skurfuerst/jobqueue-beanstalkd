<?php
namespace TYPO3\Jobqueue\Redis\Queue;

use TYPO3\FLOW3\Package\Package as BasePackage;
use TYPO3\FLOW3\Annotations as FLOW3;

/**
 * A queue implementation using beanstalkd as the queue backend
 *
 * Depends on Pheanstalk as the PHP beanstalkd client.
 */
class BeanstalkdQueue implements \TYPO3\Jobqueue\Common\Queue\QueueInterface {

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * @var \Pheanstalk\Pheanstalk
	 */
	protected $client;

	/**
	 * @var integer
	 */
	protected $defaultTimeout = NULL;

	/**
	 * Constructor
	 *
	 * @param string $name
	 * @param array $options
	 */
	public function __construct($name, array $options = array()) {
		$this->name = $name;
		if (isset($options['defaultTimeout'])) {
			$this->defaultTimeout = (integer)$options['defaultTimeout'];
		}
		$clientOptions = isset($options['client']) ? $options['client'] : array();
		$host = isset($clientOptions['host']) ? $clientOptions['host'] : '127.0.0.1';
		$port = isset($clientOptions['port']) ? $clientOptions['port'] : \Pheanstalk\Pheanstalk::DEFAULT_PORT;

		$this->client = new \Pheanstalk\Pheanstalk($host, $port, $this->defaultTimeout);
	}

	/**
	 * Publish a message to the queue
	 *
	 * @param \TYPO3\Jobqueue\Common\Queue\Message $message
	 * @return void
	 */
	public function publish(\TYPO3\Jobqueue\Common\Queue\Message $message) {
		$encodedMessage = $this->encodeMessage($message);
		$messageIdentifier = $this->client->put($encodedMessage);
		$message->setIdentifier($messageIdentifier);
		$message->setState(\TYPO3\Jobqueue\Common\Queue\Message::STATE_PUBLISHED);
	}

	/**
	 * Wait for a message in the queue and return the message for processing
	 * (without safety queue)
	 *
	 * @param int $timeout
	 * @return \TYPO3\Jobqueue\Common\Message The received message or NULL if a timeout occured
	 */
	public function waitAndTake($timeout = NULL) {
		$timeout !== NULL ? $timeout : $this->defaultTimeout;
		$pheanstalkJob = $this->client->reserve($timeout);
		if ($pheanstalkJob === NULL) {
			return NULL;
		}
		$message = $this->decodeMessage($pheanstalkJob->getData());
		$message->setIdentifier($pheanstalkJob->getId());
		$this->client->delete($pheanstalkJob);
		$message->setState(\TYPO3\Jobqueue\Common\Queue\Message::STATE_DONE);
		return $message;

	}

	/**
	 * Wait for a message in the queue and save the message to a safety queue
	 *
	 * TODO: Idea for implementing a TTR (time to run) with monitoring of safety queue. E.g.
	 * use different queue names with encoded times? With brpoplpush we cannot modify the
	 * queued item on transfer to the safety queue and we cannot update a timestamp to mark
	 * the run start time in the message, so separate keys should be used for this.
	 *
	 * @param int $timeout
	 * @return \TYPO3\Jobqueue\Common\Queue\Message
	 */
	public function waitAndReserve($timeout = NULL) {
		$timeout !== NULL ? $timeout : $this->defaultTimeout;
		$pheanstalkJobs = $this->client->reserve($timeout);
		if ($pheanstalkJobs === NULL) {
			return NULL;
		}
		$message = $this->decodeMessage($pheanstalkJobs->getData());
		$message->setIdentifier($pheanstalkJobs->getId());
		return $message;
	}

	/**
	 * Mark a message as finished
	 *
	 * @param \TYPO3\Jobqueue\Common\Queue\Message $message
	 * @return boolean TRUE if the message could be removed
	 */
	public function finish(\TYPO3\Jobqueue\Common\Queue\Message $message) {
		$messageIdentifier = $message->getIdentifier();
		$pheanstalkJob = $this->client->peek($messageIdentifier);
		$this->client->delete($pheanstalkJob);
		$message->setState(\TYPO3\Jobqueue\Common\Queue\Message::STATE_DONE);
	}

	/**
	 * Peek for messages
	 *
	 * @param integer $limit
	 * @return array Messages or empty array if no messages were present
	 */
	public function peek($limit = 1) {
		throw new \TYPO3\FLOW3\Exception('not implemented!', 1334153875);
	}

	/**
	 * Count messages in the queue
	 *
	 * @return integer
	 */
	public function count() {
		throw new \TYPO3\FLOW3\Exception('not implemented!', 1334153878);
	}

	/**
	 * Encode a message
	 *
	 * Updates the original value property of the message to resemble the
	 * encoded representation.
	 *
	 * @param \TYPO3\Jobqueue\Common\Queue\Message $message
	 * @return string
	 */
	protected function encodeMessage(\TYPO3\Jobqueue\Common\Queue\Message $message) {
		$value = json_encode($message->toArray());
		$message->setOriginalValue($value);
		return $value;
	}

	/**
	 * Decode a message from a string representation
	 *
	 * @param string $value
	 * @return \TYPO3\Jobqueue\Common\Queue\Message
	 */
	protected function decodeMessage($value) {
		$decodedMessage = json_decode($value, TRUE);
		$message = new \TYPO3\Jobqueue\Common\Queue\Message($decodedMessage['payload']);
		if (isset($decodedMessage['identifier'])) {
			$message->setIdentifier($decodedMessage['identifier']);
		}
		$message->setOriginalValue($value);
		return $message;
	}

	/**
	 *
	 * @param string $identifier
	 * @return \TYPO3\Jobqueue\Common\Queue\Message
	 */
	public function getMessage($identifier) {
		$pheanstalkJob = $this->client->peek($identifier);
		return $this->decodeMessage($pheanstalkJob->getData());
	}


}
?>