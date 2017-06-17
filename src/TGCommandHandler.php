<?php
/**
 * WildPHP - an advanced and easily extensible IRC bot written in PHP
 * Copyright (C) 2017 WildPHP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 17-6-2017
 * Time: 15:28
 */

namespace WildPHP\Modules\TGRelay;


use Collections\Dictionary;
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
	 * @param Dictionary $commandDictionary
	 */
	public function __construct(ComponentContainer $container, Dictionary $commandDictionary)
	{
		$this->setCommandDictionary($commandDictionary);
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
	 * @param \Telegram $telegram
	 * @param $chat_id
	 * @param $channel
	 * @param $username
	 *
	 * @return bool
	 */
	public function parseAndRunTGCommand(string $text, \Telegram $telegram, $chat_id, $channel, $username): bool
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
			->emit('telegram.command', [$command, $telegram, $chat_id, $parts, $channel, $username]);

		$dictionary = $this->getCommandDictionary();

		if (!$dictionary->keyExists($command))
			return false;

		$commandObject = $dictionary[$command];

		$maximumArguments = $commandObject->getMaximumArguments();
		if (count($parts) < $commandObject->getMinimumArguments() || ($maximumArguments != -1 && count($parts) > $maximumArguments))
		{
			$msg = 'Invalid argument count. (not in range of ' . $commandObject->getMinimumArguments() . ' =< x =< ' . $maximumArguments . ')';
			$telegram->sendMessage(['chat_id' => $chat_id, 'text' => $msg]);

			// We return true here so it doesn't get processed as a regular message.
			return true;
		}

		call_user_func($commandObject->getCallback(), $telegram, $chat_id, $parts, $channel, $username, $command);
		return true;
	}
}