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
 * Date: 6-7-2017
 * Time: 17:07
 */

namespace WildPHP\Modules\TGRelay;


use unreal4u\TelegramAPI\Telegram\Types\Update;

class Utils
{
	/**
	 * @param Update $update
	 * @param bool $originIsBot
	 *
	 * @return bool|string
	 */
	public static function getReplyUsername(Update $update, bool $originIsBot = false)
	{
		if (empty($update->message->reply_to_message))
			return false;

		if (!$originIsBot)
			return $update->message->reply_to_message->from->username;

		// This accounts for both normal messages and CTCP ACTION ones.
		$result = preg_match('/^<(\S+)>|^\*(\S+) /', $update->message->reply_to_message->text, $matches);

		if (!$result)
			return false;

		$matches = array_values(array_filter($matches));

		return $matches[1];
	}

	/**
	 * @param Update $update
	 *
	 * @return bool|string
	 */
	public static function getCaption(Update $update)
	{
		if (empty($update->message->caption))
			return false;

		return $update->message->caption;
	}

	/**
	 * @param Update $update
	 *
	 * @return string
	 */
	public static function getSender(Update $update)
	{
		return !empty($update->message->from->username) ? $update->message->from->username :
			trim($update->message->from->first_name . ' ' . $update->message->from->last_name);
	}

	/**
	 * @param Update $update
	 *
	 * @return string
	 *
	 */
	public static function getUpdateType(Update $update): string
	{
		//@formatter:off
		$toPoke = ['audio', 'contact', 'document', 'entities', 'game', 'location', 'photo', 'sticker', 'video', 'video_note', 'voice',
			'venue', 'new_chat_members', 'left_chat_member', 'new_chat_title', 'new_chat_photo', 'delete_chat_photo', 'migrate_to_chat_id',
			'migrate_from_chat_id', 'pinned_message', 'invoice', 'successful_payment'];
		//@formatter:on

		foreach ($toPoke as $item)
			if (!empty($update->message->$item))
				return $item;

		if (!empty($update->message))
			return 'message';

		return 'unknown';
	}
}