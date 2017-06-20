<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use Collections\Collection;

class ChannelMap extends Collection
{
	public function __construct()
	{
		parent::__construct(TelegramLink::class);
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
}