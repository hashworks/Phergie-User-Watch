<?php

namespace hashworks\Phergie\Plugin\UserWatch;

use Evenement\EventEmitterInterface;
use Phergie\Irc\Event\ServerEvent;
use Phergie\Irc\Client\React\Exception;
use Phergie\Irc\Event\UserEventInterface as Event;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use React\EventLoop\LoopInterface;

/**
 * User class.
 *
 * @category Phergie
 * @package hashworks\Phergie\Plugin\WhoisOnJoin
 */
class User {

	protected $nick     = '';
	protected $username = '';
	protected $realname = '';
	protected $host     = '';
	protected $server   = '';

	protected $ircOperator      = false;
	protected $identified       = false;
	protected $secureConnection = false;

	protected $modes    = array();
	protected $channels = array();

	/**
	 * @var Event
	 */
	protected $event;

	/**
	 * @var Queue
	 */
	protected $queue;

	/**
	 * @var EventEmitterInterface
	 */
	protected $emitter;

	/**
	 * @var LoopInterface
	 */
	protected $loop;

	public function __construct(Event $event, Queue $queue, EventEmitterInterface $emitter, LoopInterface $loop) {
		$this->event = $event;
		$this->queue = $queue;
		$this->emitter = $emitter;
		$this->loop = $loop;
	}

	/**
	 * Queue a whois. Will call the provided callbacks once done.
	 *
	 * @param callable $sucessCallback
	 * @param callable $errorCallback = NULL
	 * @return bool
	 */
	public function queueWhois(callable $sucessCallback, callable $errorCallback = NULL) {
		$nick = $this->getNick();
		if (!empty($nick)) {
			$this->queue->ircWhois('', $nick);

			$whoisUserListener = function (ServerEvent $event) use ($sucessCallback, $errorCallback) {
				$this->setServer($event->getServername());
				if (isset($event->getParams()[6])) {
					$this->setRealname($event->getParams()[6]);
				}
				$listeners = array(
						'irc.received.307' => function (ServerEvent $event) {
							if (strpos($event->getMessage(), 'identi') !== false || strpos($event->getMessage(), 'regist') !== false) {
								$this->setIdentified(true);
							}
						}, // 307 [rpl_whoisregnick, not RFC standard]
						'irc.received.rpl_whoisserver' => function (ServerEvent $event) {
							if (isset($event->getParams()[3])) {
								$this->setServer($event->getParams()[3]);
							}
						}, // 312
						'irc.received.rpl_whoisoperator' => function () {
							$this->setIrcOperator(true);
						}, // 313
						'irc.received.rpl_whoischannels' => function (ServerEvent $event) {
							if (isset($event->getParams()[2])) {
								$this->setChannels(explode(' ', $event->getParams()[2]));
							}
						}, // 319
						'irc.received.671' => function (ServerEvent $event) {
							if (strpos($event->getMessage(), 'secure') !== false) {
								$this->setSecureConnection(true);
							}
						} // 671 [rpl_whoissecure, not RFC standard]
				);

				foreach ($listeners as $event => $listener) {
					// RPL_WHOISCHANNELS can be send multiple times if the user is in many channels
					if ($event == 'irc.received.rpl_whoischannels') {
						$this->emitter->on($event, $listener);
					} else {
						$this->emitter->once($event, $listener);
					}
				}

				$this->emitter->once('irc.received.rpl_endofwhois', function () use ($listeners, $sucessCallback) {
					foreach ($listeners as $event => $listener) {
						$this->emitter->removeListener($event, $listener);
					}
					$sucessCallback();
				}); // 318
			};

			$noSuchNickListener = function () use ($whoisUserListener, $errorCallback) {
				$this->emitter->removeListener('irc.received.rpl_whoisuser', $whoisUserListener);
				if ($errorCallback !== NULL) {
					$errorCallback();
				}
			};

			$this->emitter->once('irc.received.rpl_whoisuser', $whoisUserListener); // 311
			$this->emitter->once('irc.received.err_nosuchnick', $noSuchNickListener); // 401

			$this->emitter->once('irc.received.rpl_whoisuser', function () use ($noSuchNickListener) {
				$this->emitter->removeListener('irc.received.err_nosuchnick', $noSuchNickListener);
			}); // 311

			return true;
		}
		return false;
	}

	/**
	 * Sets a user mode for the user.
	 * Example: $user->setUserMode('+iws')
	 *
	 * @link http://docs.dal.net/docs/modes.html#3
	 * @param string $mode
	 * @param string $param = null
	 */
	public function setUserMode($mode, $param = null) {
		$this->queue->ircMode($this->nick, $mode, $param);
	}

	/**
	 * Sets a channel mode.
	 * Example: $user->setChannelMode('+b', 'nickname!~username@host')
	 *
	 * @link http://docs.dal.net/docs/modes.html#2
	 * @param string $mode
	 * @param string $param = null
	 * @param string $channel = null
	 */
	public function setChannelMode($mode, $param = null, $channel = null) {
		if ($channel === null) {
			$channel = $this->event->getSource();
		}
		$this->queue->ircMode($channel, $mode, $param);
	}

	/**
	 * Kicks the user out of the channel.
	 *
	 * @param string $comment = null
	 * @param string $channel = null
	 */
	public function kick($comment = null, $channel = null) {
		if ($channel === null) {
			$channel = $this->event->getSource();
		}
		$this->queue->ircKick($channel, $this->nick, $comment);
	}

	/**
	 * Send a PRIVMSG to the channel.
	 *
	 * @param string $privmsg
	 * @param string $channel = null
	 */
	public function privmsgChannel($privmsg, $channel = null) {
		if ($channel === null) {
			$channel = $this->event->getSource();
		}
		$this->queue->ircPrivmsg($channel, $privmsg);
	}

	/**
	 * Send a PRIVMSG to the user.
	 *
	 * @param string $privmsg
	 */
	public function privmsgUser($privmsg) {
		$this->queue->ircPrivmsg($this->nick, $privmsg);
	}

	/**
	 * Send a NOTICE to the channel.
	 *
	 * @param string $message
	 * @param string $channel = null
	 */
	public function noticeChannel($message, $channel = null) {
		if ($channel === null) {
			$channel = $this->event->getSource();
		}
		$this->queue->ircNotice($channel, $message);
	}

	/**
	 * Send a NOTICE to the user.
	 *
	 * @param string $message
	 */
	public function noticeUser($message) {
		$this->queue->ircNotice($this->nick, $message);
	}

	/**
	 * @return Event
	 */
	public function getEvent () {
		return $this->event;
	}

	/**
	 * @return Queue
	 */
	public function getQueue () {
		return $this->queue;
	}

	/**
	 * @return EventEmitterInterface
	 */
	public function getEmitter () {
		return $this->emitter;
	}

	/**
	 * @return LoopInterface
	 */
	public function getLoop () {
		return $this->loop;
	}

	/**
	 * @return string
	 */
	public function getNick () {
		return $this->nick;
	}

	/**
	 * @param string $nick
	 */
	public function setNick ($nick) {
		$this->nick = $nick;
	}

	/**
	 * @return string
	 */
	public function getUsername () {
		return $this->username;
	}

	/**
	 * @param string $username
	 */
	public function setUsername ($username) {
		$this->username = $username;
	}

	/**
	 * @return string
	 */
	public function getRealname () {
		return $this->realname;
	}

	/**
	 * @param string $realname
	 */
	public function setRealname ($realname) {
		$this->realname = $realname;
	}

	/**
	 * @return string
	 */
	public function getHost () {
		return $this->host;
	}

	/**
	 * @param string $host
	 */
	public function setHost ($host) {
		$this->host = $host;
	}

	/**
	 * @return string
	 */
	public function getServer () {
		return $this->server;
	}

	/**
	 * @param string $server
	 */
	public function setServer ($server) {
		$this->server = $server;
	}

	/**
	 * @return boolean
	 */
	public function isIrcOperator () {
		return $this->ircOperator;
	}

	/**
	 * @param boolean $isIrcOperator
	 */
	public function setIrcOperator ($isIrcOperator) {
		$this->ircOperator = boolval($isIrcOperator);
	}

	/**
	 * @return boolean
	 */
	public function isIdentified () {
		return $this->identified;
	}

	/**
	 * @param boolean $isIdentified
	 */
	public function setIdentified ($isIdentified) {
		$this->identified = boolval($isIdentified);
	}

	/**
	 * @return boolean
	 */
	public function hasSecureConnection () {
		return $this->secureConnection;
	}

	/**
	 * @param boolean $hasSecureConnection
	 */
	public function setSecureConnection ($hasSecureConnection) {
		$this->secureConnection = boolval($hasSecureConnection);
	}

	/**
	 * @return string[]
	 */
	public function getModes () {
		return $this->modes;
	}

	/**
	 * @param string[] $modes
	 */
	public function setModes ($modes) {
		$this->modes = $modes;
	}

	/**
	 * @return string[]
	 */
	public function getChannels () {
		return $this->channels;
	}

	/**
	 * @param string[] $channels
	 */
	public function setChannels ($channels) {
		$this->channels = $channels;
	}



}