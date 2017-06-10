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

namespace WildPHP\Modules\TGRelay;

use Collections\Collection;
use Telegram;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Logger\Logger;
use WildPHP\Core\Tasks\Task;
use WildPHP\Core\Tasks\TaskController;

class TGRelay
{
	use ContainerTrait;

	/**
	 * @var Telegram
	 */
	protected $botObject = null;

	/**
	 * @var Collection
	 */
	protected $channelMap = [];

	/**
	 * @var FileServer
	 */
	protected $fileServer;

	/**
	 * @var string
	 */
	protected $botID = '';

	/**
	 * @var string
	 */
	protected $uri = '';

	protected $self;

	public function __construct(ComponentContainer $container)
	{
		$this->setContainer($container);

		$channelMap = Configuration::fromContainer($container)
			->get('telegram.channels')
			->getValue();
		$botID = Configuration::fromContainer($container)
			->get('telegram.botID')
			->getValue();
		$baseUri = Configuration::fromContainer($container)
			->get('telegram.uri')
			->getValue();
		$port = Configuration::fromContainer($container)
			->get('telegram.port')
			->getValue();
		$listenOn = Configuration::fromContainer($container)
			->get('telegram.listenOn')
			->getValue();

		$collection = new Collection(TelegramLink::class);
		$this->setChannelMap($collection);
		foreach ($channelMap as $chatID => $channel)
		{
			$linkObject = new TelegramLink();
			$linkObject->setChatID($chatID);
			$linkObject->setChannel($channel);
			$collection->add($linkObject);
		}

		$this->setBotID($botID);
		$this->setUri($baseUri . '/');
		$fileServer = new FileServer($this->getContainer(), $port, $listenOn);
		$this->setFileServer($fileServer);
		$tgBot = new Telegram($botID);
		$this->self = $tgBot->getMe()['result'];

		$this->setBotObject($tgBot);

		$task = new Task([$this, 'fetchTelegramMessages'], 1, [$container], 1);
		TaskController::fromContainer($container)
			->addTask($task);

		EventEmitter::fromContainer($container)
			->on('irc.line.in.privmsg', [$this, 'processIrcMessage']);
		EventEmitter::fromContainer($container)
			->on('telegram.msg.in', [$this, 'processTelegramMessage']);

		new TGCommands($container);
	}

	public function fetchTelegramMessages(Task $task, ComponentContainer $container)
	{
		$telegram = $this->getBotObject();
		$req = $telegram->getUpdates();
		for ($i = 0; $i < $telegram->UpdateCount(); $i++)
		{
			// You NEED to call serveUpdate before accessing the values of message in Telegram Class
			$telegram->serveUpdate($i);
			$chat_id = $telegram->ChatID();

			if (is_null($chat_id))
				continue;

			$username = $telegram->Username();

			Logger::fromContainer($container)
				->debug('Received message from Telegram', [
					'chatID' => $chat_id,
					'username' => $username,
					'type' => $this->getMessageContentType($telegram)
				]);

			EventEmitter::fromContainer($container)
				->emit('telegram.msg.in', [$chat_id, $username, $telegram]);
		}
	}

	public function getMessageContentType(Telegram $telegram)
	{
		$data = $telegram->getData()['message'];
		$content = array_slice($data, -1);

		return array_keys($content)[0];
	}

	/**
	 * @param int|float $chat_id
	 * @param string|null $username
	 * @param Telegram $telegram
	 */
	public function processTelegramMessage($chat_id, $username, Telegram $telegram)
	{
		if (empty($chat_id) || empty($username))
			return;

		if (!($channel = $this->findChannelForID($chat_id)))
		{
			Logger::fromContainer($this->getContainer())
				->warning('[Telegram] Received message, but no channel is linked to the chat ID', [
					'chat_id' => $chat_id
				]);

			return;
		}

		switch (($type = $this->getMessageContentType($telegram)))
		{
			case 'text':
				$this->processText($telegram, $chat_id, $channel, $username);
				break;

			case 'entities':
				$this->processEntities($telegram, $chat_id, $channel, $username);
				break;

			case 'photo':
				$this->processPhoto($telegram, $chat_id, $channel, $username);
				break;

			case 'document':
			case 'voice':
			case 'sticker':
				$this->processGenericFile($telegram, $chat_id, $channel, $username);
				break;

			default:
				Logger::fromContainer($this->getContainer())
					->warning('Message type not implemented!', [
						'type' => $this->getMessageContentType($telegram),
						'data' => $telegram->getData()
					]);
		}
	}

	public function processGenericFile(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		$fileID = $telegram->getData()['message'][$this->getMessageContentType($telegram)]['file_id'];
		$fileData = $telegram->getFile($fileID);

		if (!empty($fileData['error_code']))
			return;

		$path = $fileData['result']['file_path'];
		$idHash = sha1($chat_id);

		$uri = '';
		if (!file_exists(WPHP_ROOT_DIR . 'tgstorage/' . $path))
			$this->getFileServer()
				->downloadFileAsync($path, $this->getBotID(), $idHash, $uri);
		$uri = $this->getUri() . $uri;
		$message = '[TG] ' . $username . ' uploaded a file: ' . $uri;
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	public function processPhoto(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		$fileID = end($telegram->getData()['message'][$this->getMessageContentType($telegram)])['file_id'];
		$fileData = $telegram->getFile($fileID);

		if (!empty($fileData['error_code']))
			return;

		$path = $fileData['result']['file_path'];
		$idHash = sha1($chat_id);

		$uri = '';
		if (!file_exists(WPHP_ROOT_DIR . 'tgstorage/' . $path))
			$this->getFileServer()
				->downloadFileAsync($path, $this->getBotID(), $idHash, $uri);
		$uri = $this->getUri() . $uri;
		$message = '[TG] ' . $username . ' uploaded a photo: ' . $uri;
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	public function processEntities(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		$text = $telegram->getData()['message']['text'];
		$text = str_replace("\n", ' | ', str_replace("\r", "\n", $text));

		$offset = $telegram->getData()['message']['entities'][0]['offset'];
		$length = $telegram->getData()['message']['entities'][0]['length'];

		$command = trim(substr($text, $offset + 1, $length));
		$arguments = array_filter(explode(' ', trim(substr($text, $length))));
		EventEmitter::fromContainer($this->getContainer())
			->emit('telegram.command', [$command, $telegram, $chat_id, $arguments, $channel, $username]);
		EventEmitter::fromContainer($this->getContainer())
			->emit('telegram.command.' . $command, [$telegram, $chat_id, $arguments, $channel, $username]);
		Logger::fromContainer($this->getContainer())
			->debug('[Telegram] Command found', [
				'command' => $command,
				'args' => $arguments
			]);

		if (!empty(EventEmitter::fromContainer($this->getContainer())->listeners('telegram.command.' . $command)))
			return;

		$this->processText($telegram, $chat_id, $channel, $username);
	}

	public function processText(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		$text = $telegram->getData()['message']['text'];
		$text = str_replace("\n", ' | ', str_replace("\r", "\n", $text));

		if (array_key_exists('reply_to_message', $telegram->getData()['message']))
		{
			$reply = $telegram->getData()['message']['reply_to_message'];
			$replyUsername = $reply['from']['username'];
			if ($replyUsername == $this->self['username'])
				$replyUsername = $this->parseIrcUsername($reply['text']);

			$text = '@' . $replyUsername . ': ' . $text;
		}
		$message = '[TG] <' . $username . '> ' . $text;
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	public function processIrcMessage(PRIVMSG $ircMessage)
	{
		if ($ircMessage->isCtcp())
			Logger::fromContainer($this->getContainer())->debug('CTCP found!', [
				'nickname' => $ircMessage->getNickname(),
				'verb' => $ircMessage->getCtcpVerb(),
				'args' => $ircMessage->getMessage()
			]);

		if (!($chat_id = $this->findIDForChannel($ircMessage->getChannel())))
			return;

		$telegram = $this->getBotObject();
		if ($ircMessage->isCtcp() && $ircMessage->getCtcpVerb() == 'ACTION')
			$message = '*' . $ircMessage->getNickname() . ' ' . $ircMessage->getMessage() . '*';
		else
			$message = '<' . $ircMessage->getNickname() . '> ' . $ircMessage->getMessage();

		$telegram->sendMessage(['chat_id' => $chat_id, 'text' => $message]);
	}

	/**
	 * @return Telegram
	 */
	public function getBotObject(): Telegram
	{
		return $this->botObject;
	}

	/**
	 * @param Telegram $botObject
	 */
	public function setBotObject(Telegram $botObject)
	{
		$this->botObject = $botObject;
	}

	/**
	 * @return Collection
	 */
	public function getChannelMap(): Collection
	{
		return $this->channelMap;
	}

	/**
	 * @param Collection $channelMap
	 */
	public function setChannelMap(Collection $channelMap)
	{
		$this->channelMap = $channelMap;
	}

	/**
	 * @param string $channel
	 *
	 * @return bool|float|int|string
	 */
	public function findIDForChannel(string $channel)
	{
		$channelMap = $this->getChannelMap();
		$link = $channelMap->find(function (TelegramLink $link) use ($channel)
		{
			return $link->getChannel() == $channel;
		});

		if (!$link)
			return false;

		return $link->getChatID();
	}

	/**
	 * @param $id
	 *
	 * @return bool|string
	 */
	public function findChannelForID($id)
	{
		$channelMap = $this->getChannelMap();
		$link = $channelMap->find(function (TelegramLink $link) use ($id)
		{
			return $link->getChatID() == $id;
		});

		if (!$link)
			return false;

		return $link->getChannel();
	}

	/**
	 * @param string $text
	 *
	 * @return bool|string
	 */
	public function parseIrcUsername(string $text)
	{
		// This accounts for both normal messages and CTCP ACTION ones.
		$result = preg_match('/^<(\S+)>|^\*(\S+) /', $text, $matches);

		if ($result == false)
			return false;

		$matches = array_values(array_filter($matches));

		return $matches[1];
	}

	/**
	 * @return FileServer
	 */
	public function getFileServer(): FileServer
	{
		return $this->fileServer;
	}

	/**
	 * @param FileServer $fileServer
	 */
	public function setFileServer(FileServer $fileServer)
	{
		$this->fileServer = $fileServer;
	}

	/**
	 * @return string
	 */
	public function getBotID(): string
	{
		return $this->botID;
	}

	/**
	 * @param string $botID
	 */
	public function setBotID(string $botID)
	{
		$this->botID = $botID;
	}

	/**
	 * @return string
	 */
	public function getUri(): string
	{
		return $this->uri;
	}

	/**
	 * @param string $uri
	 */
	public function setUri(string $uri)
	{
		$this->uri = $uri;
	}
}