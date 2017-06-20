<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use Collections\Dictionary;
use unreal4u\TelegramAPI\Abstracts\TelegramTypes;
use unreal4u\TelegramAPI\Telegram\Methods\GetFile;
use unreal4u\TelegramAPI\Telegram\Methods\GetMe;
use unreal4u\TelegramAPI\Telegram\Methods\GetUpdates;
use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\Telegram\Types\Custom\UpdatesArray;
use unreal4u\TelegramAPI\Telegram\Types\File;
use unreal4u\TelegramAPI\Telegram\Types\Update;
use unreal4u\TelegramAPI\TgLog;
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
use WildPHP\Modules\TGRelay\ChannelMap;

class TGRelay
{
	use ContainerTrait;

	/**
	 * @var TgLog
	 */
	protected $botObject = null;

	/**
	 * @var ChannelMap()
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
	 * @var TelegramTypes
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

		$tgBot = new TgLog($botID);
		$this->self = $tgBot->performApiRequest(new GetMe());

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
			->on('telegram.msg.in', [$this, 'routeUpdate']);

		// Unlock the refusal flag
		EventEmitter::fromContainer($container)
			->on('irc.line.in.001', function () use ($container)
			{
				Logger::fromContainer($this->getContainer())
					->debug('Setting refusal flag to false');
				$this->refuseMessages = false;
			});

		EventEmitter::fromContainer($container)
			->on('wildphp.init-modules.after', function () use ($commandHandler, $container)
			{
				// Emit an event to let other modules know that commands can be added.
				EventEmitter::fromContainer($container)
					->emit('telegram.commands.add', [$commandHandler]);
			});
	}

	/**
	 * @param array $channelMap
	 */
	public function setupChannelMap(array $channelMap)
	{
		$collection = new ChannelMap();
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
		$baseURI = Configuration::fromContainer($this->getContainer())
			->get('telegram.uri')
			->getValue();

		$fileServer = new FileServer($this->getContainer(), $port, $listenOn, $baseURI);
		$this->setFileServer($fileServer);
	}

	/**
	 * @param Task $task
	 * @param ComponentContainer $container
	 */
	public function fetchTelegramMessages(Task $task, ComponentContainer $container)
	{
		$tgLog = $this->getBotObject();
		$getUpdates = new GetUpdates();

		try
		{
			/** @var UpdatesArray $updates */
			$updates = $tgLog->performApiRequest($getUpdates);
			foreach ($updates->traverseObject() as $update)
			{
				EventEmitter::fromContainer($container)
					->emit('telegram.msg.in', [$update, $tgLog]);
			}
		}
		catch (\Exception $e)
		{
			return;
		}
	}

	/**
	 * @param Update $update
	 *
	 * @return string
	 *
	 */
	public function getUpdateType(Update $update): string
	{
		$toPoke = ['audio',
			'contact',
			'document',
			'entities',
			'game',
			'location',
			'photo',
			'sticker',
			'video',
			'video_note',
			'venue',
			'new_chat_members',
			'left_chat_member',
			'new_chat_title',
			'new_chat_photo',
			'delete_chat_photo',
			'migrate_to_chat_id',
			'migrate_from_chat_id',
			'pinned_message',
			'invoice',
			'successful_payment'];

		foreach ($toPoke as $item)
			if (!empty($update->message->$item))
				return $item;

		if (!empty($update->message))
			return 'message';

		return 'unknown';
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 */
	public function routeUpdate(Update $update, TgLog $telegram)
	{
		if ($this->refuseMessages)
			return;

		$chat_id = $update->message->chat->id;
		$channel = $this->getChannelMap()->findChannelForID($chat_id);

		switch ($this->getUpdateType($update))
		{
			case 'audio':
				$this->processDownloadableFile($update, $telegram, $channel, 'uploaded an audio file', $update->message->audio->file_id);
				break;

			case 'document':
				$this->processDownloadableFile($update, $telegram, $channel, 'uploaded a document', $update->message->document->file_id);
				break;

			case 'entities':
				$this->processEntities($update, $telegram, $channel);
				break;

			case 'message':
				$this->processGenericMessage($update, $telegram, $channel);
				break;

			case 'photo':
				$array = $update->message->photo->traverseObject();
				$this->processDownloadableFile($update, $telegram, $channel, 'uploaded a picture',
					end($array)->file_id);
				break;

			case 'sticker':
				$this->processDownloadableFile($update, $telegram, $channel, 'sent a sticker', $update->message->sticker->file_id);
				break;

			case 'video':
				$this->processDownloadableFile($update, $telegram, $channel, 'uploaded a video', $update->message->video->file_id);
				break;

			case 'voice':
				$this->processDownloadableFile($update, $telegram, $channel, 'uploaded a voice recording', $update->message->voice->file_id);
				break;

			case 'contact':
			case 'game':
			case 'location':
			case 'venue':
			case 'invoice':
				$this->processUnsupportedMessage($update, $telegram, $channel);
				break;

			default:
				Logger::fromContainer($this->getContainer())
					->debug('Message type not implemented!', [
						'type' => $this->getUpdateType($update),
						'data' => $update
					]);
		}
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $associatedChannel
	 */
	public function processEntities(Update $update, TgLog $telegram, string $associatedChannel)
	{
		$text = $update->message->text;
		$chat_id = $update->message->chat->id;
		$username = $update->message->from->username;
		$coloredUsername = static::colorNickname($username);

		$result = TGCommandHandler::fromContainer($this->getContainer())
			->parseAndRunTGCommand($text, $telegram, $chat_id, $associatedChannel, $username, $coloredUsername);

		if (!$result)
			$this->processGenericMessage($update, $telegram, $associatedChannel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $associatedChannel
	 */
	public function processGenericMessage(Update $update, TgLog $telegram, string $associatedChannel)
	{
		if (empty($associatedChannel))
			return;

		$message = '<' . static::colorNickname($update->message->from->username) . '> ' . $update->message->text;

		if (($replyUsername = $this->getReplyUsername($update)))
			$message = '@' . static::colorNickname($replyUsername) . ': ' . $message;

		$message = '[TG] ' . $message;

		Queue::fromContainer($this->getContainer())
			->privmsg($associatedChannel, $message);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $associatedChannel
	 */
	public function processUnsupportedMessage(Update $update, TgLog $telegram, string $associatedChannel)
	{
		if (empty($associatedChannel))
			return;

		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $update->message->chat->id;
		$sendMessage->text = 'Unable to relay message to IRC because it is unsupported';
		$telegram->performApiRequest($sendMessage);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $associatedChannel
	 * @param string $fileMessage
	 * @param mixed $file_id
	 */
	public function processDownloadableFile(Update $update, TgLog $telegram, string $associatedChannel, string $fileMessage, $file_id)
	{
		if (empty($associatedChannel))
			return;

		$uri = $this->getFileServer()->downloadFile($file_id, $update->message->chat->id, $telegram);

		if (empty($uri))
			return;

		$message = static::colorNickname($update->message->from->username) . ' ' . $fileMessage . ': ' . $uri;

		if (!empty($update->message->caption))
			$message .= ' (' . $update->message->caption . ')';

		if (($replyUsername = $this->getReplyUsername($update)))
			$message = '@' . static::colorNickname($replyUsername) . ': ' . $message;

		$message = '[TG] ' . $message;

		Queue::fromContainer($this->getContainer())
			->privmsg($associatedChannel, $message);
	}

	/**
	 * @param PRIVMSG $ircMessage
	 */
	public function processIrcMessage(PRIVMSG $ircMessage)
	{
		if (!($chat_id = $this->getChannelMap()->findIDForChannel($ircMessage->getChannel())))
			return;

		$telegram = $this->getBotObject();
		if ($ircMessage->isCtcp() && $ircMessage->getCtcpVerb() == 'ACTION')
			$message = '*' . $ircMessage->getNickname() . ' ' . $ircMessage->getMessage() . '*';
		else
			$message = '<' . $ircMessage->getNickname() . '> ' . $ircMessage->getMessage();

		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $chat_id;
		$sendMessage->text = $message;
		$telegram->performApiRequest($sendMessage);
	}

	/**
	 * @return TgLog
	 */
	public function getBotObject(): TgLog
	{
		return $this->botObject;
	}

	/**
	 * @param TgLog $botObject
	 */
	public function setBotObject(TgLog $botObject)
	{
		$this->botObject = $botObject;
	}

	/**
	 * @return ChannelMap
	 */
	public function getChannelMap(): ChannelMap
	{
		return $this->channelMap;
	}

	/**
	 * @param ChannelMap() $channelMap
	 */
	public function setChannelMap(ChannelMap $channelMap)
	{
		$this->channelMap = $channelMap;
	}

	/**
	 * @param Update $update
	 *
	 * @return bool|string
	 *
	 */
	public function getReplyUsername(Update $update)
	{
		if (empty($update->message->reply_to_message))
			return false;

		if ($update->message->reply_to_message->from->username != $this->self->username)
			return $update->message->reply_to_message->from->username;

		// This accounts for both normal messages and CTCP ACTION ones.
		$result = preg_match('/^<(\S+)>|^\*(\S+) /', $update->message->reply_to_message->text, $matches);

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
	public static function colorNickname(string $nickname): string
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
}