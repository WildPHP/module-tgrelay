<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 17-6-2017
 * Time: 15:28
 */

namespace WildPHP\Modules\TGRelay;


use WildPHP\Core\Collection;
use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\TgLog;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\ComponentTrait;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\EventEmitter;

class TGCommandHandler extends CommandHandler
{
	use ComponentTrait;

	/**
	 * CommandHandler constructor.
	 *
	 * @param ComponentContainer $container
	 * @param Collection $commandCollection
	 */
	public function __construct(ComponentContainer $container, Collection $commandCollection)
	{
		$this->setCommandCollection($commandCollection);
		$this->setContainer($container);
	}

	/**
	 * @param PRIVMSG $privmsg
	 * @param Queue $queue
	 *
	 * @throws \ErrorException
	 */
	public function parseAndRunCommand(PRIVMSG $privmsg, Queue $queue)
	{
		throw new \ErrorException('Cannot call CommandHandler::parseAndRunCommand from a TGCommandHandler');
	}

	/**
	 * @param string $text
	 * @param TgLog $telegram
	 * @param $chat_id
	 * @param $channel
	 * @param $username
	 *
	 * @param string $coloredUsername
	 *
	 * @return bool
	 */
	public function parseAndRunTGCommand(string $text, TgLog $telegram, $chat_id, $channel, $username, string $coloredUsername = ''): bool
	{
		$parts = explode(' ', $text);

		// Remove newlines and excessive spaces.
		foreach ($parts as $index => $part)
			$parts[$index] = trim($part);

		$command = array_shift($parts);
		$command = substr($command, 0, 1) == '/' ? substr($command, 1) : false;

		if (!$command)
			return false;

		EventEmitter::fromContainer($this->getContainer())
			->emit('telegram.command', [$command, $telegram, $chat_id, $parts, $channel, $username, $coloredUsername]);

		$dictionary = $this->getCommandCollection();

		if (!$dictionary->offsetExists($command))
			return false;

		$commandObject = $dictionary[$command];

		$maximumArguments = $commandObject->getMaximumArguments();
		if (count($parts) < $commandObject->getMinimumArguments() || ($maximumArguments != -1 && count($parts) > $maximumArguments))
		{
			$sendMessage = new SendMessage();
			$sendMessage->text = 'Invalid argument count. (not in range of ' . $commandObject->getMinimumArguments() . ' =< x =< ' . $maximumArguments . ')';
			$sendMessage->chat_id = $chat_id;
			$telegram->performApiRequest($sendMessage);

			// We return true here so it doesn't get processed as a regular message.
			return true;
		}

		call_user_func($commandObject->getCallback(), $telegram, $chat_id, $parts, $channel, $username, $command, $coloredUsername);
		return true;
	}
}