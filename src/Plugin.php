<?php

namespace hashworks\Phergie\Plugin\UserWatch;

use React\EventLoop\LoopInterface;
use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Client\React\LoopAwareInterface;
use Phergie\Irc\Event\UserEventInterface as Event;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;

/**
 * Plugin class.
 *
 * @category Phergie
 * @package hashworks\Phergie\Plugin\UserWatch
 */
class Plugin extends AbstractPlugin implements LoopAwareInterface {

	/**
	 * @var LoopInterface
	 */
	protected $loop;

	protected $joinCallback;
	protected $partCallback;
	protected $quitCallback;

	/**
	 * @param array $callbacks
	 * @throws Exception
	 */
	public function __construct(array $callbacks) {
		if (isset($callbacks['joinCallback']) && is_callable($callbacks['joinCallback'])) $this->joinCallback = $callbacks['joinCallback'];
		if (isset($callbacks['partCallback']) && is_callable($callbacks['partCallback'])) $this->partCallback = $callbacks['partCallback'];
		if (isset($callbacks['quitCallback']) && is_callable($callbacks['quitCallback'])) $this->quitCallback = $callbacks['quitCallback'];
	}

	/**
	 * Sets the event loop for the implementing class to use.
	 *
	 * @param \React\EventLoop\LoopInterface $loop
	 */
	public function setLoop(LoopInterface $loop) {
		$this->loop = $loop;
	}

	/**
	 * @return LoopInterface
	 */
	public function getLoop() {
		return $this->loop;
	}

	/**
	 * @return array
	 */
	public function getSubscribedEvents () {
		return array(
				'irc.received.join' => 'handleJoin',
				'irc.received.part' => 'handlePart',
				'irc.received.quit' => 'handleQuit'
		);
	}

	/**
	 * @param Event $event
	 * @param Queue $queue
	 * @param callable $callback
	 */
	public function handle(Event $event, Queue $queue, callable $callback) {
		$nick = $event->getNick();

		if (empty($nick) || $nick == $event->getConnection()->getNickname()) {
			// Don't handle our own
			return;
		}

		$user = new User($event, $queue, $this->emitter, $this->loop);
		$user->setNick($nick);
		$user->setUsername($event->getUsername());
		$user->setHost($event->getHost());
		$callback($user);
	}

	/**
	 * @param Event $event
	 * @param Queue $queue
	 */
	public function handleJoin(Event $event, Queue $queue) {
		$callback = $this->joinCallback;
		$this->handle($event, $queue, $callback);
	}

	/**
	 * @param Event $event
	 * @param Queue $queue
	 */
	public function handlePart(Event $event, Queue $queue) {
		$callback = $this->partCallback;
		$this->handle($event, $queue, $callback);
	}

	/**
	 * @param Event $event
	 * @param Queue $queue
	 */
	public function handleQuit(Event $event, Queue $queue) {
		$callback = $this->quitCallback;
		$this->handle($event, $queue, $callback);
	}
}
