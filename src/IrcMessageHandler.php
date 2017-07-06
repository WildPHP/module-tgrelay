<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;


use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\QueueItem;
use WildPHP\Core\EventEmitter;

class IrcMessageHandler
{
	/**
	 * @var ChannelMap
	 */
	protected $channelMap;

	/**
	 * @var TgLog
	 */
	protected $telegramObject;

	/**
	 * IrcMessageHandler constructor.
	 *
	 * @param ComponentContainer $container
	 * @param TgLog $telegram
	 * @param ChannelMap $channelMap
	 */
	public function __construct(ComponentContainer $container, TgLog $telegram, ChannelMap $channelMap)
	{
		$this->telegramObject = $telegram;
		$this->channelMap = $channelMap;

		EventEmitter::fromContainer($container)
			->on('irc.line.in.privmsg', [$this, 'incoming']);
		EventEmitter::fromContainer($container)
			->on('irc.line.out.privmsg', [$this, 'outgoing']);
	}

	/**
	 * @param QueueItem $queueItem
	 */
	public function outgoing(QueueItem $queueItem)
	{
		/** @var PRIVMSG $privmsg */
		$privmsg = $queueItem->getCommandObject();

		$channel = $privmsg->getChannel();
		$chat_id = $this->getChannelMap()->findIDForChannel($channel);

		if (!$chat_id || in_array('relay_ignore', $privmsg->getMessageParameters()))
			return;

		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $chat_id;
		$sendMessage->text = $privmsg->getMessage();
		$this->getTelegramObject()->performApiRequest($sendMessage);
	}

	/**
	 * @param PRIVMSG $ircMessage
	 */
	public function incoming(PRIVMSG $ircMessage)
	{
		if (!($chat_id = $this->getChannelMap()
			->findIDForChannel($ircMessage->getChannel()))
		)
			return;

		if ($ircMessage->isCtcp() && $ircMessage->getCtcpVerb() == 'ACTION')
			$message = '*' . $ircMessage->getNickname() . ' ' . $ircMessage->getMessage() . '*';
		else
			$message = '<' . $ircMessage->getNickname() . '> ' . $ircMessage->getMessage();

		$telegram = $this->getTelegramObject();
		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $chat_id;
		$sendMessage->text = $message;
		$telegram->performApiRequest($sendMessage);
	}

	/**
	 * @return ChannelMap
	 */
	public function getChannelMap(): ChannelMap
	{
		return $this->channelMap;
	}

	/**
	 * @return TgLog
	 */
	public function getTelegramObject(): TgLog
	{
		return $this->telegramObject;
	}
}