<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use WildPHP\Core\Commands\Command;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\EventEmitter;
use Yoshi2889\Collections\Collection;
use Yoshi2889\Container\ComponentTrait;

class TGCommandHandler extends CommandHandler
{
	use ComponentTrait;

	/** @noinspection PhpMissingParentConstructorInspection */

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

		if (!$dictionary->offsetExists($command) || array_key_exists($command, $this->aliases))
			return false;

		/** @var Command $commandObject */
		$commandObject = $dictionary[$command] ?? $this->aliases[$command];

		try
		{
			$parameterDefinitions = $commandObject->getParameterDefinitions();
			$parts = $parameterDefinitions->validateArgumentArray($parts);

			if (!$parameterDefinitions->validateArgumentCount($parts))
				throw new \InvalidArgumentException();
		}
		catch (\InvalidArgumentException $e)
		{
			$sendMessage = new SendMessage();
			$sendMessage->text = 'Invalid arguments.';
			$sendMessage->chat_id = $chat_id;
			$telegram->performApiRequest($sendMessage);
			
			// We return true here so it doesn't get processed as a regular message.
			return true;
		}

		call_user_func($commandObject->getCallback(), $telegram, $chat_id, $parts, $channel, $username, $command, $coloredUsername);
		return true;
	}
}