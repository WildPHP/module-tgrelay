<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use Collections\Collection;
use Collections\Dictionary;
use Telegram;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\Connection\TextFormatter;
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

	/**
	 * @var array
	 */
	protected $self;

	/**
	 * Avoid spamming channels when the bot joins them and refuse to parse new messages when starting up.
	 *
	 * @var bool
	 */
	protected $refuseMessages = true;

	/**
	 * TGRelay constructor.
	 *
	 * @param ComponentContainer $container
	 */
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

		$this->setBotID($botID);
		$this->setUri($baseUri . '/');
		$tgBot = new Telegram($botID);
		$this->self = $tgBot->getMe()['result'];

		$this->setBotObject($tgBot);
		$this->setupChannelMap($channelMap);
		$this->setupFileServer();

		$commandHandler = new TGCommandHandler($container, new Dictionary());
		$container->store($commandHandler);

		new TGCommands($container);

		$task = new Task([$this, 'fetchTelegramMessages'], 1, [$container], 1);
		TaskController::fromContainer($container)
			->addTask($task);

		EventEmitter::fromContainer($container)
			->on('irc.line.in.privmsg', [$this, 'processIrcMessage']);
		EventEmitter::fromContainer($container)
			->on('telegram.msg.in', [$this, 'processTelegramMessage']);

		// Unlock the refusal flag
		EventEmitter::fromContainer($container)
			->on('irc.line.in.001', function () use ($container)
			{
				Logger::fromContainer($this->getContainer())->debug('Setting refusal flag to false');
				$this->refuseMessages = false;
			});

		EventEmitter::fromContainer($container)
			->on('wildphp.init-modules.after', function () use ($commandHandler, $container)
			{
				// Emit an event to let other modules know that commands can be added.
				EventEmitter::fromContainer($container)->emit('telegram.commands.add', [$commandHandler]);
			});
	}

	/**
	 * @param array $channelMap
	 */
	public function setupChannelMap(array $channelMap)
	{
		$collection = new Collection(TelegramLink::class);
		$this->setChannelMap($collection);

		if (!empty($channelMap))
			foreach ($channelMap as $chatID => $channel)
			{
				$linkObject = new TelegramLink();
				$linkObject->setChatID($chatID);
				$linkObject->setChannel($channel);
				$collection->add($linkObject);
			}
	}

	public function setupFileServer()
	{
		$port = Configuration::fromContainer($this->getContainer())
			->get('telegram.port')
			->getValue();
		$listenOn = Configuration::fromContainer($this->getContainer())
			->get('telegram.listenOn')
			->getValue();

		$fileServer = new FileServer($this->getContainer(), $port, $listenOn);
		$this->setFileServer($fileServer);
	}

	/**
	 * @param Task $task
	 * @param ComponentContainer $container
	 */
	public function fetchTelegramMessages(Task $task, ComponentContainer $container)
	{
		$telegram = $this->getBotObject();
		$telegram->getUpdates();
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

	/**
	 * @param Telegram $telegram
	 *
	 * @return string
	 */
	public function getMessageContentType(Telegram $telegram): string
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
		if (empty($chat_id) || empty($username) || $this->refuseMessages)
			return;

		$channel = $this->findChannelForID($chat_id);

		switch ($this->getMessageContentType($telegram))
		{
			case 'text':
				$this->processText($telegram, $chat_id, $channel, $username);
				break;

			case 'entities':
				$this->processEntities($telegram, $chat_id, $channel, $username);
				break;

			case 'caption':
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

	/**
	 * @param Telegram $telegram
	 * @param $chat_id
	 * @param string $channel
	 * @param string $username
	 */
	public function processGenericFile(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		if (empty($channel))
			return;

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

		$caption = !empty($telegram->getData()['message']['caption']) ? $telegram->getData()['message']['caption'] : '';
		$message = '[TG] ' . $this->colorNickname($username) . ' uploaded a file: ' . $uri . (!empty($caption) ? ' (' . $caption . ')' : '');
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	/**
	 * @param Telegram $telegram
	 * @param $chat_id
	 * @param string $channel
	 * @param string $username
	 */
	public function processPhoto(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		if (empty($channel))
			return;

		$fileID = end($telegram->getData()['message']['photo'])['file_id'];
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

		$caption = !empty($telegram->getData()['message']['caption']) ? $telegram->getData()['message']['caption'] : '';
		$message = '[TG] ' . $this->colorNickname($username) . ' uploaded a photo: ' . $uri . (!empty($caption) ? ' (' . $caption . ')' : '');
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	/**
	 * @param Telegram $telegram
	 * @param $chat_id
	 * @param string $channel
	 * @param string $username
	 */
	public function processEntities(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		$text = $telegram->getData()['message']['text'];
		$coloredUsername = $this->colorNickname($username);
		$result = TGCommandHandler::fromContainer($this->getContainer())->parseAndRunTGCommand($text, $telegram, $chat_id, $channel, $username, $coloredUsername);

		if (!$result)
			$this->processText($telegram, $chat_id, $channel, $username);
	}

	/**
	 * @param Telegram $telegram
	 * @param $chat_id
	 * @param string $channel
	 * @param string $username
	 */
	public function processText(\Telegram $telegram, $chat_id, string $channel, string $username)
	{
		if (empty($channel))
			return;

		$text = $telegram->getData()['message']['text'];
		$text = str_replace("\n", ' | ', str_replace("\r", "\n", $text));

		if (array_key_exists('reply_to_message', $telegram->getData()['message']))
		{
			$reply = $telegram->getData()['message']['reply_to_message'];
			$replyUsername = $reply['from']['username'];
			if ($replyUsername == $this->self['username'])
				$replyUsername = $this->parseIrcUsername($reply['text']);

			$text = '@' . $this->colorNickname($replyUsername) . ': ' . $text;
		}
		$message = '[TG] <' . $this->colorNickname($username) . '> ' . $text;
		Queue::fromContainer($this->getContainer())
			->privmsg($channel, $message);
	}

	/**
	 * @param PRIVMSG $ircMessage
	 */
	public function processIrcMessage(PRIVMSG $ircMessage)
	{
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

		if (!$result)
			return false;

		$matches = array_values(array_filter($matches));

		return $matches[1];
	}

	/**
	 * @param string $nickname
	 *
	 * @return string
	 */
	public function colorNickname(string $nickname): string
	{
		$num = 0;
		foreach (str_split($nickname) as $char)
		{
			$num += ord($char);
		}
		$num = abs($num) % 15; // We have 15 colors to pick from.

		return TextFormatter::color($nickname, $num);
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