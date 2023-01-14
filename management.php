<?php

# Commands for Bot administrators
if ($v->chat_type == 'private' && ($v->isAdmin() || $isOwner)) {
	if ($v->command == 'broadcast' || strpos($v->query_data, 'broadcast') === 0) {
		if ($configs['redis']['status']) {
			if ($rget = $db->rget('BotXCC-broadcast-' . $v->user_id)) {
				$settings = json_decode($rget, 1);
			} else {
				$settings = [
					'tables'					=> [
						'users'		=> 1,
						'groups'	=> 1,
						'channels'	=> 1
					],
					'disable_notification'		=>	0,
					'disable_web_page_preview'	=>	0
				];
			}
			$reverse = [1, 0];
			$notifemoji = ['🔔', '🔕'];
			$webpremoji = ['🖇', '❌'];
			$databemoji = ['❌', '📨'];
			if (isset($v->query_data) && strpos($v->query_data, 'broadcast-') === 0) {
				$e = explode('-', $v->query_data);
				if ($e[1] == 1) {
					$table = array_keys($settings['tables'])[$e[2]];
					$settings['tables'][$table] = $reverse[round($settings['tables'][$table])];
				} elseif ($e[1] == 2) {
					$settings['disable_notification'] = $reverse[round($settings['disable_notification'])];
				} elseif ($e[1] == 3) {
					$settings['disable_web_page_preview'] = $reverse[round($settings['disable_web_page_preview'])];
				}
			}
			$db->rset('BotXCC-broadcast-' . $v->user_id, json_encode($settings), (60 * 5));
			if (!$t) {
				$notification = '';
				$t = $bot->bold('📨 ' . $tr->getTranslation('broadcastTitle')) . PHP_EOL . $bot->italic($tr->getTranslation('broadcastDescription'));
				$buttons = [
					[$bot->createInlineButton($databemoji[round($settings['tables']['users'])] . ' ' . $tr->getTranslation('buttonUsers'), 'broadcast-1-0'), $bot->createInlineButton($databemoji[round($settings['tables']['groups'])] . ' ' . $tr->getTranslation('buttonGroups'), 'broadcast-1-1'), $bot->createInlineButton($databemoji[round($settings['tables']['channels'])] . ' ' . $tr->getTranslation('buttonChannels'), 'broadcast-1-2')],
					[$bot->createInlineButton($notifemoji[round($settings['disable_notification'])], 'broadcast-2'), $bot->createInlineButton($tr->getTranslation('buttonWebpagePreview', [$webpremoji[round($settings['disable_web_page_preview'])]]), 'broadcast-3')],
					[$bot->createInlineButton('❎ ' . $tr->getTranslation('buttonCloseMenu'), 'management-1')]
				];
			}
		} else {
			$t = '⚠️ ' . $tr->getTranslation('broadcastNotAvailable');
		}
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, '', false);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
			$bot->deleteMessage($v->chat_id, $v->message_id);
		}
		die;
	} elseif ($rget = $db->rget('BotXCC-broadcast-' . $v->user_id) && !$v->query_data && !$v->command) {
		$settings = json_decode($rget, 1);
		$bot->editConfigs('response', 1);
		$bot->editConfigs('disable_notification', $settings['disable_notification']);
		$bot->editConfigs('disable_web_page_preview', $settings['disable_web_page_preview']);
		if ($v->text) {
			$m = $bot->sendMessage($v->chat_id, $v->text, $v->update['message']['reply_markup']['inline_keyboard'], $v->entities);
		} else {
			$m = $bot->copyMessage($v->chat_id, $v->chat_id, $v->message_id, 0, $v->update['message']['reply_markup']['inline_keyboard']);
		}
		if ($m['ok']) {
			$bm = $bot->sendMessage($v->chat_id, '🔄');
			$chats = [];
			foreach ($settings['tables'] as $table => $status) {
				if ($status) {
					$fromt = $db->query('SELECT id FROM ' . $table . ' WHERE id != ?', [$v->user_id], 2);
					if (isset($fromt[0]['id'])) $chats = array_merge($chats, $fromt);
					unset($fromt);
				}
			}
			$bot->editConfigs('response', 0);
			fastcgi_finish_request();
			if (empty($chats)) {
				$bot->editText($v->chat_id, $bm['result']['message_id'], $tr->getTranslation('broadcastNoChat'));
			} else {
				$db->rdel('BotXCC-broadcast-' . $v->user_id);
				$bot->editText($v->chat_id, $bm['result']['message_id'], $tr->getTranslation('broadcastForwardedTo', [0, round(count($chats))]));
				$start_time = time();
				$xtime = $start_time + 2;
				foreach ($chats as $chat) {
					if ($v->text) {
						$bot->sendMessage($chat['id'], $v->text, $v->update['message']['reply_markup']['inline_keyboard'], $v->entities);
					} else {
						$bot->copyMessage($chat['id'], $v->chat_id, $v->message_id, 0, $v->update['message']['reply_markup']['inline_keyboard']);
					}
					$chatcount += 1;
					if ($xtime <= time()) {
						$bot->editText($v->chat_id, $bm['result']['message_id'], $tr->getTranslation('broadcastForwardedTo', [round($chatcount), round(count($chats))]));
						$xtime = time() + 5;
					}
				}
				$total_time = time() - $start_time;
				sleep(1);
				if ($total_time <= 1) {
					$time_total = $tr->getTranslation('textSecond');
				} elseif ($total_time < 60) {
					$time_total = $tr->getTranslation('textSeconds', [round($total_time)]);
				} elseif ($total_time > 60 && $total_time > 120) {
					$time_total = $tr->getTranslation('textMinute');
				} else {
					$minutes = explode('.', round($total_time / 60, 1))[0];
					$time_total = $tr->getTranslation('textMinutes');
				}
				$bot->editText($v->chat_id, $bm['result']['message_id'], '📨 ' . $tr->getTranslation('broadcastComplete', [round($chatcount), $time_total]));
			}
		} else {
			$bot->sendMessage($v->chat_id, 'Telegram Error: ' . $bot->code($m['description'], 1));
		}
		die;
	} elseif ($v->command == 'management' || strpos($v->query_data, 'management') === 0) {
		if (isset($v->query_data) && strpos($v->query_data, 'management-') === 0) {
			$e = explode('-', $v->query_data);
			if ($e[1] == 1) {
				# Close management panel
				if ($configs['redis']['status'] && $db->rget('BotXCC-broadcast-' . $v->user_id)) $db->rdel('BotXCC-broadcast-' . $v->user_id);
				$bot->deleteMessage($v->chat_id, $v->message_id);
				$bot->answerCBQ($v->query_id);
				die;
			} elseif ($e[1] == 2 && isset($e[2])) {
				# Databases info
				$table = $db->tables[$e[2]];
				$e[1] = 3;
				if ($table == 'users') {
					$tuser = $db->query('SELECT id, name, surname, username, lang, status, ban FROM users WHERE id = ? LIMIT 1', [$e[3]], 1);
					if (!isset($tuser['id'])) {
						$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
						die;
					}
					if (!$tuser['name']) $tuser['name'] = 'Deactived user';
					if (!$tuser['surname']) $tuser['surname'] = '❌';
					if (!$tuser['username']) {
						$tuser['username'] = '❌';
					} else {
						$tuser['username'] = '@' . $tuser['username'];
					}
					if (!$tuser['lang']) $tuser['lang'] = 'en';
					$tuser['status'][0] = strtoupper($tuser['status'][0]);
					if ($tuser['ban']) {
						if ($e[4] == 'unban') {
							$db->unban($tuser['id']);
							$tuser['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
							if ($v->user_id !== $tuser['id'] && !in_array($tuser['id'], $botxconf['checkers'])) $febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
						} else {
							$tuser['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
							$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
						}
					} else {
						if ($e[4] == 'ban') {
							if ($v->user_id !== $tuser['id'] && in_array($tuser['id'], $botxconf['checkers'])) {
								$bot->answerCBQ($v->query_id, '🚫 ' . $tr->getTranslation('messageBanAdministrators'));
								$tuser['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
							} else {
								$db->ban($tuser['id']);
								$tuser['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
								$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
							}
						} else {
							$tuser['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
							if ($v->user_id !== $tuser['id'] && !in_array($tuser['id'], $botxconf['checkers'])) {
								$febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
							}
						}
					}
					$formenu = 2;
					$mcount = 0;
					foreach ($febuttons as $febutton) {
						if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
						$buttons[$mcount][] = $febutton;
					}
					$t = 'ℹ️ ' . $tr->getTranslation('messageManagementUser', [$bot->specialchars($tuser['name']), $bot->specialchars($tuser['surname']), $bot->specialchars($tuser['id']), $bot->specialchars($tuser['username']), $bot->specialchars($tuser['lang']), $bot->specialchars($tuser['status']), $bot->specialchars($tuser['ban'])]);
				} elseif ($table == 'groups') {
					if ($e[4] == 'admins') {
						$tchat = $db->query('SELECT id, title, admins FROM groups WHERE id = ? or id = ? LIMIT 1', ['-100' . $e[3], '-' . $e[3]], 1);
						if (!isset($tchat['id'])) {
							$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
							die;
						}
						$list = '';
						$e[1] = 2;
						$e[2] .= '-' . $e[3];
						$tchat['admins'] = json_decode($tchat['admins'], 1);
						if (!is_array($tchat['admins']) || empty($tchat['admins'])) {
							$list = PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						} else {
							foreach ($tchat['admins'] as $admin) {
								if ($admin['status'] == 'creator' && !isset($e[5])) {
									$febuttons[] = $bot->createInlineButton('👑 ' . $admin['user']['first_name'], 'management-2-' . str_replace('-' . $e[3], '', $e[2]) . '-' . $e[3] . '-admins-' . $admin['user']['id']);
									if ($admin['user']['username']) $admin['user']['first_name'] = $bot->text_link($admin['user']['first_name'], 'https://t.me/' . $admin['user']['username']);
									$list .= PHP_EOL . '👑 ' . $admin['user']['first_name'] . ' [' . $bot->code($admin['user']['id']) . ']';
								}
								$cadmins[$admin['user']['id']] = $admin;
							}
							if ($e[5]) {
								$e[2] .= '-' . $e[4];
								$admin = $cadmins[$e[5]];
								if (isset($admin['custom_title'])) $ctitle = $bot->italic(' (' . $admin['custom_title'] . ')');
								$emoji = ['❌', '✅'];
								foreach ($v->getGroupsPerms() as $perm) {
									if (isset($admin[$perm]) || $admin['status'] !== 'creator') {
										$bool = round($admin[$perm]);
									} else {
										$bool = 1;
									}
									$perm[0] = strtoupper($perm[0]);
									$perms .= PHP_EOL . str_replace('_', ' ', $perm) . ': ' . $emoji[$bool];
								}
								$adtype = [$tr->getTranslation('textUser'), 'Bot'];
								$list = PHP_EOL . $bot->bold($adtype[round($admin['user']['is_bot'])] . ': ') . $bot->tag($admin['user']['id'], $admin['user']['first_name']) . $ctitle . PHP_EOL . $bot->code($admin['user']['id']) . PHP_EOL . $perms;
							} else {
								$num = 0;
								foreach ($cadmins as $admin) {
									if ($admin['status'] !== 'creator') {
										$num += 1;
										$list .= PHP_EOL . $num . '️⃣ ' . $bot->tag($admin['user']['id'], $admin['user']['first_name']) . ' [' . $bot->code($admin['user']['id']) . ']';
										$febuttons[] = $bot->createInlineButton($num . '️⃣ ' . $admin['user']['first_name'], 'management-2-' . str_replace('-' . $e[3], '', $e[2]) . '-' . $e[3] . '-admins-' . $admin['user']['id']);
									}
								}
							}
						}
						$formenu = 2;
						$mcount = 0;
						foreach ($febuttons as $febutton) {
							if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
							$buttons[$mcount][] = $febutton;
						}
						$t = '👮🏻‍♂️ ' . $tr->getTranslation('messageAdministratorsList', [$tchat['title'], $list]);
					} elseif ($e[4] == 'permissions') {
						$tchat = $db->query('SELECT id, title, permissions FROM groups WHERE id = ? or id = ? LIMIT 1', ['-100' . $e[3], '-' . $e[3]], 1);
						if (!isset($tchat['id'])) {
							$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
							die;
						}
						$list = '';
						$e[1] = 2;
						$e[2] = $e[2] . '-' . $e[3];
						$tchat['permissions'] = json_decode($tchat['permissions'], 1);
						if (!is_array($tchat['permissions']) || empty($tchat['permissions'])) {
							$list = PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						} else {
							$emoji = ['❌', '✅'];
							foreach ($tchat['permissions'] as $perm => $bool) {
								$perm[0] = strtoupper($perm[0]);
								$list .= PHP_EOL . str_replace('_', ' ', $perm) . ': ' . $emoji[round($bool)];
							}
						}
						$t = '💬 ' . $tr->getTranslation('messagePermissionsList', [$tchat['title'], $list]);
					} else {
						$tchat = $db->query('SELECT id, title, description, username, status, ban FROM groups WHERE id = ? or id = ? LIMIT 1', ['-100' . $e[3], '-' . $e[3]], 1);
						if (!isset($tchat['id'])) {
							$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
							die;
						}
						if (!$tchat['username']) {
							$tchat['username'] = '❌';
						} else {
							$tchat['username'] = '@' . $tchat['username'];
						}
						if (!$tchat['description']) {
							$tchat['description'] = '❌';
						}
						$tchat['status'][0] = strtoupper($tchat['status'][0]);
						$febuttons[] = $bot->createInlineButton('👮🏻‍♂️ ' . $tr->getTranslation('buttonAdministrators'), 'management-2-' . $e[2] . '-' . $e[3] . '-admins');
						$febuttons[] = $bot->createInlineButton('💬 ' . $tr->getTranslation('buttonPermissions'), 'management-2-' . $e[2] . '-' . $e[3] . '-permissions');
						if ($tchat['ban']) {
							if ($e[4] == 'unban') {
								$db->unban($tchat['id']);
								$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								$febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
							} else {
								$tchat['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
								$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
							}
						} else {
							if ($e[4] == 'ban') {
								if (in_array($tchat['id'], $botxconf['checkers'])) {
									$bot->answerCBQ($v->query_id, '🚫 ' . $tr->getTranslation('messageBanAdministrators'));
									$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								} else {
									$ok = $db->ban($tchat['id']);
									$tchat['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
									$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
								}
							} else {
								$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								if (!in_array($tchat['id'], $botxconf['checkers'])) {
									$febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
								}
							}
						}
						$formenu = 2;
						$mcount = 0;
						foreach ($febuttons as $febutton) {
							if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
							$buttons[$mcount][] = $febutton;
						}
						$t = 'ℹ️ ' . $tr->getTranslation('messageManagementGroup', [$bot->specialchars($tchat['title']), $bot->specialchars($tchat['id']), $bot->specialchars($tchat['username']), $bot->specialchars($tchat['description']), $bot->specialchars($tchat['status']), $bot->specialchars($tchat['ban'])]);
					}
				} elseif ($table == 'channels') {
					if ($e[4] == 'admins') {
						$tchat = $db->query('SELECT id, title, admins FROM channels WHERE id = ? LIMIT 1', ['-100' . $e[3]], 1);
						if (!isset($tchat['id'])) {
							$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
							die;
						}
						$list = '';
						$e[1] = 2;
						$e[2] .= '-' . $e[3];
						$tchat['admins'] = json_decode($tchat['admins'], 1);
						if (!is_array($tchat['admins']) || empty($tchat['admins'])) {
							$list = PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						} else {
							foreach ($tchat['admins'] as $admin) {
								if ($admin['status'] == 'creator' && !isset($e[5])) {
									$febuttons[] = $bot->createInlineButton('👑 ' . $admin['user']['first_name'], 'management-2-' . str_replace('-' . $e[3], '', $e[2]) . '-' . $e[3] . '-admins-' . $admin['user']['id']);
									if ($admin['user']['username']) $admin['user']['first_name'] = $bot->text_link($admin['user']['first_name'], 'https://t.me/' . $admin['user']['username']);
									$list .= PHP_EOL . '👑 ' . $admin['user']['first_name'] . ' [' . $bot->code($admin['user']['id']) . ']';
								}
								$cadmins[$admin['user']['id']] = $admin;
							}
							if ($e[5]) {
								$e[2] .= '-' . $e[4];
								$admin = $cadmins[$e[5]];
								$emoji = ['❌', '✅'];
								foreach ($v->getChannelsPerms() as $perm) {
									if (isset($admin[$perm]) || $admin['status'] !== 'creator') {
										$bool = round($admin[$perm]);
									} else {
										$bool = 1;
									}
									$perm[0] = strtoupper($perm[0]);
									$perms .= PHP_EOL . str_replace('_', ' ', $perm) . ': ' . $emoji[$bool];
								}
								$adtype = [$tr->getTranslation('textUser'), 'Bot'];
								$list = PHP_EOL . $bot->bold($adtype[round($admin['user']['is_bot'])] . ': ') . $bot->tag($admin['user']['id'], $admin['user']['first_name']) . PHP_EOL . $bot->code($admin['user']['id']) . PHP_EOL . $perms;
							} else {
								$num = 0;
								foreach ($cadmins as $admin) {
									if ($admin['status'] !== 'creator') {
										$num += 1;
										$list .= PHP_EOL . $num . '️⃣ ' . $bot->tag($admin['user']['id'], $admin['user']['first_name']) . ' [' . $bot->code($admin['user']['id']) . ']';
										$febuttons[] = $bot->createInlineButton($num . '️⃣ ' . $admin['user']['first_name'], 'management-2-' . str_replace('-' . $e[3], '', $e[2]) . '-' . $e[3] . '-admins-' . $admin['user']['id']);
									}
								}
							}
						}
						$formenu = 2;
						$mcount = 0;
						foreach ($febuttons as $febutton) {
							if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
							$buttons[$mcount][] = $febutton;
						}
						$t = '👮🏻‍♂️ ' . $tr->getTranslation('messageAdministratorsList', [$tchat['title'], $list]);
					} else {
						$tchat = $db->query('SELECT id, title, description, username, status, ban FROM channels WHERE id = ? LIMIT 1', ['-100' . $e[3]], 1);
						if (!isset($tchat['id'])) {
							$bot->answerCBQ($v->query_id, '😔 ' . $tr->getTranslation('messageChatNotFound'));
							die;
						}
						if (!$tchat['username']) {
							$tchat['username'] = '❌';
						} else {
							$tchat['username'] = '@' . $tchat['username'];
						}
						if (!$tchat['description']) {
							$tchat['description'] = '❌';
						}
						$tchat['status'][0] = strtoupper($tchat['status'][0]);
						$febuttons[] = $bot->createInlineButton('👮🏻‍♂️ ' . $tr->getTranslation('buttonAdministrators'), 'management-2-' . $e[2] . '-' . $e[3] . '-admins');
						if ($tchat['ban']) {
							if ($e[4] == 'unban') {
								$db->unban($tchat['id']);
								$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								$febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
							} else {
								$tchat['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
								$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
							}
						} else {
							if ($e[4] == 'ban') {
								if (in_array($tchat['id'], $botxconf['checkers'])) {
									$bot->answerCBQ($v->query_id, '🚫 ' . $tr->getTranslation('messageBanAdministrators'));
									$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								} else {
									$ok = $db->ban($tchat['id']);
									$tchat['ban'] = '🚫 ' . $tr->getTranslation('textBanned');
									$febuttons[] = $bot->createInlineButton('✅ ' . $tr->getTranslation('buttonUnBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-unban');
								}
							} else {
								$tchat['ban'] = '✅ ' . $tr->getTranslation('textNotBanned');
								if (in_array($tchat['id'], $botxconf['checkers'])) {
									$febuttons[] = $bot->createInlineButton('🚫 ' . $tr->getTranslation('buttonBan'), 'management-2-' . $e[2] . '-' . $e[3] . '-ban');
								}
							}
						}
						$formenu = 2;
						$mcount = 0;
						foreach ($febuttons as $febutton) {
							if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
							$buttons[$mcount][] = $febutton;
						}
						$t = 'ℹ️ ' . $tr->getTranslation('messageManagementChannel', [$bot->specialchars($tchat['title']), $bot->specialchars($tchat['id']), $bot->specialchars($tchat['username']), $bot->specialchars($tchat['description']), $bot->specialchars($tchat['status']), $bot->specialchars($tchat['ban'])]);
					}
				}
				$buttons[][] = $bot->createInlineButton('◀️  ' . $tr->getTranslation('buttonBack'), 'management-' . $e[1] . '-' . $e[2]);
			} elseif ($e[1] == 3) {
				# Database explorer
				if (!$configs['database']['status']) {
					$bot->answerCBQ($v->query_id, '❎ No database available!');
					die;
				}
				if (isset($e[2])) {
					$table = $db->tables[$e[2]];
					if ($table == 'users') {
						$t = '👤 ' . $tr->getTranslation('messageManagementDatabaseUsers') . PHP_EOL;
						if (isset($e[3]) && is_numeric($e[3])) {
							$page = round($e[3]);
							if ($page > 1) $prevpage = true;
						} else {
							$page = 1;
						}
						$users = $db->query('SELECT id, name, surname, username FROM users ORDER BY id DESC ' . $db->limit(6, (($page * 5) - 5)), 0, 2);
						if (!empty($users) && !isset($users['error'])) {
							if (isset($users[5])) {
								$nextpage = true;
								unset($users[5]);
							}
							$num = 0;
							foreach ($users as $tuser) {
								$num += 1;
								$emo = $num . '️⃣';
								$febuttons[] = $bot->createInlineButton($emo . ' ' . $tuser['name'], 'management-2-0-' . $tuser['id']);
								if ($tuser['username']) $tuser['name'] = $bot->text_link($tuser['name'], 'https://t.me/' . $tuser['username'], 1);
								$t .= PHP_EOL . $emo . ' ' . $tuser['name'] . ' [' . $bot->code($tuser['id']) . ']';
							}
							$formenu = 2;
							$mcount = 0;
							foreach ($febuttons as $febutton) {
								if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
								$buttons[$mcount][] = $febutton;
							}
						} else {
							$t .= PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						}
					} elseif ($table == 'groups') {
						$t = '👥 ' . $tr->getTranslation('messageManagementDatabaseGroups') . PHP_EOL;
						if (isset($e[3]) && is_numeric($e[3])) {
							$page = round($e[3]);
							if ($page > 1) {
								$prevpage = true;
							}
						} else {
							$page = 1;
						}
						$groups = $db->query('SELECT id, title, username FROM groups ORDER BY id DESC ' . $db->limit(6, (($page * 5) - 5)), 0, 2);
						if (!empty($groups)) {
							if (isset($groups[5])) {
								$nextpage = true;
								unset($groups[5]);
							}
							$num = 0;
							foreach ($groups as $tchat) {
								$num += 1;
								$emo = $num . '️⃣';
								$febuttons[] = $bot->createInlineButton($emo . ' ' . $tchat['title'], 'management-2-1-' . str_replace('-', '', str_replace('-100', '', $tchat['id'])));
								if ($tchat['username']) $tchat['title'] = $bot->text_link($tchat['title'], 'https://t.me/' . $tchat['username'], 1);
								$t .= PHP_EOL  . $emo . ' ' . $tchat['title'] . ' [' . $bot->code($tchat['id']) . ']';
							}
							$formenu = 2;
							$mcount = 0;
							foreach ($febuttons as $febutton) {
								if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
								$buttons[$mcount][] = $febutton;
							}
						} else {
							$t .= PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						}
					} elseif ($table == 'channels') {
						$t = '📢 ' . $tr->getTranslation('messageManagementDatabaseChannels') . PHP_EOL;
						if (isset($e[3]) && is_numeric($e[3])) {
							$page = round($e[3]);
							if ($page > 1) {
								$prevpage = true;
							}
						} else {
							$page = 1;
						}
						$channels = $db->query('SELECT id, title, username FROM channels ORDER BY id DESC ' . $db->limit(6, (($page * 5) - 5)), 0, 2);
						if (!empty($channels)) {
							if (isset($channels[5])) {
								$nextpage = true;
								unset($channels[5]);
							}
							$num = 0;
							foreach ($channels as $tchat) {
								$num += 1;
								$emo = $num . '️⃣';
								$febuttons[] = $bot->createInlineButton($emo . ' ' . $tchat['title'], 'management-2-2-' . str_replace('-100', '', $tchat['id']));
								if ($tchat['username']) $tchat['title'] = $bot->text_link($tchat['title'], 'https://t.me/' . $tchat['username'], 1);
								$t .= PHP_EOL  . $emo . ' ' . $tchat['title'] . ' [' . $bot->code($tchat['id']) . ']';
							}
							$formenu = 2;
							$mcount = 0;
							foreach ($febuttons as $febutton) {
								if (isset($buttons[$mcount]) && count($buttos[$mcount]) >= $formenu) $mcount += 1;
								$buttons[$mcount][] = $febutton;
							}
						} else {
							$t .= PHP_EOL . $bot->italic($tr->getTranslation('textEmptyList'));
						}
					} else {
						$bot->answerCBQ($v->query_id, '⚠️ Unknown database!', true);
						die;
					}
					$pager = [];
					if ($prevpage) $pager[] = $bot->createInlineButton('⬅️', 'management-3-' . $e[2] . '-' . round($page - 1));
					if ($nextpage) $pager[] = $bot->createInlineButton('➡️', 'management-3-' . $e[2] . '-' . round($page + 1));
					if (!empty($pager)) $buttons[] = $pager;
					$buttons[] = [
						$bot->createInlineButton('◀️  ' . $tr->getTranslation('buttonBack'), 'management-3')
					];
				} else {
					$t = '🗄 ' . $tr->getTranslation('messageManagementDatabases');
					$buttons = [
						[$bot->createInlineButton('👤 ' . $tr->getTranslation('textUsers'), 'management-3-0'), $bot->createInlineButton('👥 ' . $tr->getTranslation('textGroups'), 'management-3-1')],
						[$bot->createInlineButton('📢 ' . $tr->getTranslation('textChannels'), 'management-3-2')],
						[$bot->createInlineButton('◀️  ' . $tr->getTranslation('buttonBack'), 'management'), $bot->createInlineButton('❎ ' . $tr->getTranslation('buttonCloseMenu'), 'management-1')]
					];
				}
			} elseif ($e[1] == 4) {
				# Subscribers count
				if (!$configs['database']['status']) {
					$bot->answerCBQ($v->query_id, '❎ No database available!');
					die;
				}
				$count = [
					'users' 	=> 1,
					'groups' 	=> 0,
					'channels'	=> 0
				];
				$cu = $db->query('SELECT COUNT(id) FROM users', [], 1);
				if (isset($cu['count'])) {
					$count['users'] = round($cu['count']);
				} elseif (isset($cu['COUNT(id)'])) {
					$count['users'] = round($cu['COUNT(id)']);
				}
				$cg = $db->query('SELECT COUNT(id) FROM groups', [], 1);
				if (isset($cg['count'])) {
					$count['groups'] = round($cg['count']);
				} elseif (isset($cg['COUNT(id)'])) {
					$count['groups'] = round($cg['COUNT(id)']);
				}
				$cc = $db->query('SELECT COUNT(id) FROM channels', [], 1);
				if (isset($cc['count'])) {
					$count['channels'] = round($cc['count']);
				} elseif (isset($cc['COUNT(id)'])) {
					$count['channels'] = round($cc['COUNT(id)']);
				}
				$activityButtons[] = $bot->createInlineButton('⏰ ' . $tr->getTranslation('buttonActivity'), 'management-4-2');
				if ($e[2] == 1) {
					$cau = $db->query('SELECT COUNT(id) FROM users WHERE status = ?', ['started'], 1);
					if (isset($cau['count'])) {
						$count['a_users'] = round($cau['count']);
					} elseif (isset($cu['COUNT(id)'])) {
						$count['a_users'] = round($cau['COUNT(id)']);
					}
					$cag = $db->query('SELECT COUNT(id) FROM groups WHERE status = ?', ['active'], 1);
					if (isset($cag['count'])) {
						$count['a_groups'] = round($cag['count']);
					} elseif (isset($cag['COUNT(id)'])) {
						$count['a_groups'] = round($cag['COUNT(id)']);
					}
					$cac = $db->query('SELECT COUNT(id) FROM channels WHERE status = ?', ['active'], 1);
					if (isset($cac['count'])) {
						$count['a_channels'] = round($cac['count']);
					} elseif (isset($cac['COUNT(id)'])) {
						$count['a_channels'] = round($cac['COUNT(id)']);
					}
					$list = $bot->italic($tr->getTranslation('buttonActivity')) . PHP_EOL . PHP_EOL . $bot->bold('👤 ' . $tr->getTranslation('textUsers') . ': ') . $count['a_users'] . '/' . $count['users'] . PHP_EOL . $bot->bold('👥 ' . $tr->getTranslation('textGroups') . ': ') . $count['a_groups'] . '/' . $count['groups'] . PHP_EOL . $bot->bold('📢 ' . $tr->getTranslation('textChannels') . ': ') . $count['a_channels'] . '/' . $count['channels'];
				} elseif ($e[2] == 2) {
					if ($e[3] == 1) {
						$last = 60 * 60 * 24 * 365;
						$last_time = $tr->getTranslation('textYear');
					} elseif ($e[3] == 2) {
						$last = 60 * 60 * 24 * 30;
						$last_time = $tr->getTranslation('textMonth');
					} elseif ($e[3] == 3) {
						$last = 60 * 60 * 24;
						$last_time = $tr->getTranslation('textDay');
					} else {
						$last = 60 * 60;
						$last_time = $tr->getTranslation('textHour');
					}
					$time = time() - $last;
					$activityButtons = [
						$bot->createInlineButton($tr->getTranslation('textHour'), 'management-4-2'),
						$bot->createInlineButton($tr->getTranslation('textDay'), 'management-4-2-3'),
						$bot->createInlineButton($tr->getTranslation('textMonth'), 'management-4-2-2'),
						$bot->createInlineButton($tr->getTranslation('textYear'), 'management-4-2-1'),
					];
					$cau = $db->query('SELECT COUNT(id) FROM users WHERE last_seen > ?', [$time], 1);
					if (isset($cau['count'])) {
						$count['a_users'] = round($cau['count']);
					} elseif (isset($cu['COUNT(id)'])) {
						$count['a_users'] = round($cau['COUNT(id)']);
					}
					$cag = $db->query('SELECT COUNT(id) FROM groups WHERE last_seen > ?', [$time], 1);
					if (isset($cag['count'])) {
						$count['a_groups'] = round($cag['count']);
					} elseif (isset($cag['COUNT(id)'])) {
						$count['a_groups'] = round($cag['COUNT(id)']);
					}
					$cac = $db->query('SELECT COUNT(id) FROM channels WHERE last_seen > ?', [$time], 1);
					if (isset($cac['count'])) {
						$count['a_channels'] = round($cac['count']);
					} elseif (isset($cac['COUNT(id)'])) {
						$count['a_channels'] = round($cac['COUNT(id)']);
					}
					$list = $bot->italic($tr->getTranslation('textActivityInTheLast') . ' ' . $last_time) . PHP_EOL . PHP_EOL . $bot->bold('👤 ' . $tr->getTranslation('textUsers') . ': ') . $count['a_users'] . '/' . $count['users'] . PHP_EOL . $bot->bold('👥 ' . $tr->getTranslation('textGroups') . ': ') . $count['a_groups'] . '/' . $count['groups'] . PHP_EOL . $bot->bold('📢 ' . $tr->getTranslation('textChannels') . ': ') . $count['a_channels'] . '/' . $count['channels'];
				} else {
					$list = PHP_EOL . $bot->bold('👤 ' . $tr->getTranslation('textUsers') . ': ') . $count['users'] . PHP_EOL . $bot->bold('👥 ' . $tr->getTranslation('textGroups') . ': ') . $count['groups'] . PHP_EOL . $bot->bold('📢 ' . $tr->getTranslation('textChannels') . ': ') . $count['channels'];
				}
				$t = '🗄 ' . $tr->getTranslation('messageManagementSubscribers') . PHP_EOL . $list;
				$buttons = [
					[$bot->createInlineButton('🔄 ' . $tr->getTranslation('buttonUpdate'), $v->query_data)],
					[$bot->createInlineButton('✅ ' . $tr->getTranslation('buttonActiveSubscribers'), 'management-4-1')],
					[],
				];
				$buttons[] = $activityButtons;
				$buttons[] = [$bot->createInlineButton('◀️  ' . $tr->getTranslation('buttonBack'), 'management'), $bot->createInlineButton('❎ ' . $tr->getTranslation('buttonCloseMenu'), 'management-1')];
			}
		}
		if (!$t) {
			$t = '⚙️ ' . $tr->getTranslation('messageManagement');
			$buttons = [
				[$bot->createInlineButton('🗄 ' . $tr->getTranslation('buttonDatabase'), 'management-3')],
				[$bot->createInlineButton('👥 ' . $tr->getTranslation('buttonSubscribers'), 'management-4')],
				[$bot->createInlineButton('❎ ' . $tr->getTranslation('buttonCloseMenu'), 'management-1')]
			];
		}
		if ($v->query_id) {
			$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, '', false);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
			$bot->deleteMessage($v->chat_id, $v->message_id);
		}
		die;
	} elseif (strpos($v->command, 'ban') === 0) {
		if (strpos($v->command, 'ban ') === 0) {
			$e = explode(' ', $v->command, 2);
			if (is_numeric($e[1])) {
				$args = ['id' => $e[1]];
			} else {
				$args = ['username' => str_replace('@', '', $e[1])];
			}
			if ($tchat = $db->getUser($args) && isset($tchat['id'])) {
				$emoji = '👤';
			} elseif ($tchat = $db->getGroup($args) && isset($tchat['id'])) {
				$emoji = '👥';
			} elseif ($tchat = $db->getChannel($args) && isset($tchat['id'])) {
				$emoji = '📢';
			}
			if ($tchat['id'] == $botinfo['owner'] || in_array($tchat['id'], $botxconf['checkers'])) {
				$t = $bot->bold('🚫 ' . $tr->getTranslation('messageBanAdministrators'));
			} elseif ($db->isBanned($tchat['id'])) {
				$t = $bot->bold('✅ ' . $tr->getTranslation('messageChatAlreadyBanned'));
			} elseif ($tchat['id']) {
				$ban = $db->ban($e[1]);
				if ($name = $tchat['name']) {
					
				} elseif ($name = $tchat['title']) {
					
				} else {
					$name = 'Unknown';
				}
				$t = $bot->bold('✅ ' . $tr->getTranslation('messageBannedChat')) . PHP_EOL . $emoji . ' ' . $bot->italic($name, 1);
			} else {
				$t = $bot->bold('❌ ' . $tr->getTranslation('messageChatNotFound'));
			}
		} else {
			$t = $bot->bold('❌ ' . $tr->getTranslation('messageChatNotFound'));
		}
		$bot->sendMessage($v->chat_id, $t);
		die;
	} elseif (strpos($v->command, 'unban') === 0) {
		if (strpos($v->command, 'unban ') === 0) {
			$e = explode(' ', $v->command, 2);
			if (is_numeric($e[1])) {
				$args = ['id' => $e[1]];
			} else {
				$args = ['username' => str_replace('@', '', $e[1])];
			}
			if ($tchat = $db->getUser($args) && isset($tchat['id'])) {
				$emoji = '👤';
			} elseif ($tchat = $db->getGroup($args) && isset($tchat['id'])) {
				$emoji = '👥';
			} elseif ($tchat = $db->getChannel($args) && isset($tchat['id'])) {
				$emoji = '📢';
			}
			if (!$db->isBanned($tchat['id'])) {
				$t = $bot->bold('✅ ' . $tr->getTranslation('messageChatNotBanned'));
			} elseif ($tchat['id']) {
				$ban = $db->unban($e[1]);
				if ($name = $tchat['name']) {
					
				} elseif ($name = $tchat['title']) {
					
				} else {
					$name = 'Unknown';
				}
				$t = $bot->bold('✅ ' . $tr->getTranslation('messageUnbannedChat')) . PHP_EOL . $emoji . ' ' . $bot->italic($name, 1);
			} else {
				$t = $bot->bold('❌ ' . $tr->getTranslation('messageChatNotFound'));
			}
		} else {
			$t = $bot->bold('❌ ' . $tr->getTranslation('messageChatNotFound'));
		}
		$bot->sendMessage($v->chat_id, $t);
		die;
	}
}

?>
