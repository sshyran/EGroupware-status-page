<?php
/**
 * Hooks for Status app
 *
 * @link http://www.egroupware.org
 * @author Hadi Nategh <hn-At-egroupware.org>
 * @package Status
 * @copyright (c) 2019 by Hadi Nategh <hn-At-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Status;

use EGroupware\Api;

class Hooks {
	/**
	 * App name
	 * var string
	 */
	const APPNAME = 'status';


	/**
	 * Status items
	 *
	 * @return array returns an array of status items as sorted based on fav preferences
	 *
	 * @todo favorites and sorting result
	 */
	public static function statusItems ()
	{
		$status = [];
		$hooks = Api\Hooks::implemented('status-getStatus');
		foreach($hooks as $app)
		{
			$s = Api\Hooks::process(['location'=>'status-getStatus', 'app'=>$app], $app);
			if (!empty($s[$app])) $status = array_merge_recursive ($status, $s[$app]);
		}


		//TODO: consider favorites and sorting orders
		foreach ($status as &$s)
		{
			if (is_array($s['id'])) $s['id'] = $s['id'][0];
			$result [] = $s;
		}
		return $result;
	}

	/**
	 * Get status
	 * @param array $data info regarding the running hook
	 *
	 * @return array returns an array of users with their status
	 *
	 * Status array structure:
	 * [
	 *		[id] => [
	 *			'id' => account_lid,
	 *			'account_id' => account_id,
	 *			'icon' => Icon to show as avatar for the item,
	 *			'hint' => Text to show as tooltip for the item,
	 *			'stat' => [
	 *				[status id] => [
	 *					'notifications' => An integer number representing number of notifications,
	 *										this is an aggregation value which might gets added up
	 *										with other stat id related to the item.
	 *					'active' => int value to show item activeness
	 *				]
	 *			]
	 *		]
	 * ]
	 *
	 * An item example:
	 * [
	 *		'hn' => [
	 *			'id' => 'hn',
	 *			'account_id' => 7,
	 *			'icon' => Api\Egw::link('/api/avatar.php', [
	 *				'contact_id' => 7,
	 * 				'etag' => 11
	 * 			]),
	 *			'hint' => 'Hadi Nategh (hn@egroupware.org)',
	 *			'stat' => [
	 *				'status' => [
	 *					'notifications' => 5,
	 *					'active' => 1
	 *				]
	 *			]
	 *		]
	 * ]
	 *
	 */
	public static function getStatus ($data)
	{
		if ($data['app'] != self::APPNAME) return [];

		$stat = [];

		$contact_obj = new Api\Contacts();

		Api\Cache::setSession(self::APPNAME, 'account_state', md5(json_encode($users = self::getUsers())));

		foreach ($users as $user)
		{
			if (in_array($user['account_lid'], ['anonymous', $GLOBALS['egw_info']['user']['account_lid']]))
			{
				continue;
			}
			$contact = $contact_obj->read('account:'.$user['account_id'], true);
			$id = self::getUserName($user['account_lid']);
			if ($id)
			{
				$stat [$id] = [
					'id' => $id,
					'account_id' => $user['account_id'],
					'icon' => $contact['photo'],
					'hint' => $contact['n_given'] . ' ' . $contact['n_family'],
					'stat' => [
						'status' => [
							'active' => $user['online'],
							'lname' => $contact['n_family'],
							'fname' => $contact['n_given']
						]
					],
					'lastlogin' => $user['account_lastlogin'],
				];
			}
		}
		uasort ($stat, function ($a ,$b){
			if ($a['stat']['egw']['active'] == $b['stat']['egw']['active'])
			{
				return $b['lastlogin'] - $a['lastlogin'];
			}
			return ($a['stat']['egw']['active'] < $b['stat']['egw']['active']) ? 1 : -1;
		});
		return $stat;
	}

	/**
	 * get actions
	 *
	 * @return array return an array of actions
	 */
	public static function get_actions ()
	{
		return [
			'fav' => [
				'caption' => 'Add to favorites',
				'allowOnMultiple' => false,
				'onExecute' => 'javaScript:app.status.handle_actions'
			],
			'unfavorite' => [
				'caption' => 'Remove from favorites',
				'allowOnMultiple' => false,
				'enabled' => false,
				'onExecute' => 'javaScript:app.status.handle_actions'
			]
		];
	}

	/**
	 * Get all implemented stat keys
	 * @return type
	 */
	public static function getStatKeys ()
	{
		return Api\Hooks::implemented('status-getStatus');
	}

	/**
	 * Get username from account_lid
	 *
	 * @param type $_user = null if user given then use user as account lid
	 * @return string return username
	 */
	public static function getUserName($_user = null)
	{
		return $_user ? $_user : $GLOBALS['egw_info']['user']['account_lid'];
	}

	/**
	 * Update state
	 */
	public static function updateState()
	{
		$account_state = Api\Cache::getSession(self::APPNAME, 'account_state');
		$current_state = md5(json_encode(self::getUsers()));
		$response = Api\Json\Response::get();
		if ($account_state != $current_state)
		{
			// update the status list
			$response->call('app.status.refresh');
		}
		// nothing to update
	}

	/**
	 * Query list of active online users ordered by lastlogin
	 *
	 * @return array
	 */
	public static function getUsers ()
	{
		$users = $rows = $readonlys = $onlines = [];
		$accesslog = new \admin_accesslog();


		// get list of users
		\admin_ui::get_users([
			'filter' => 'accounts',
			'order' => 'account_lastlogin',
			'sort' => 'DESC',
			'active' => true
		], $users);

		// get list of interactive online users
		$total = $accesslog->get_rows(array('session_list' => 'active'), $rows, $readonlys);
		if ($total > 0)
		{
			unset($rows['no_lo'], $rows['no_total']);
			foreach ($rows as $row)
			{
				if ($row['account_id'] == $GLOBALS['egw_info']['user']['account_id']) continue;
				$onlines [$row['account_id']] = true;
			}
		}

		foreach($users as &$user)
		{
			if ($onlines[$user['account_id']]) $user['online'] = true;
		}
		return $users;
	}

	/**
	 * Searches rocketchat users and accounts
	 *
	 * Find entries that match query parameter (from link system) and format them
	 * as the widget expects, a list of {id: ..., label: ..., icon: ...} objects
	 */
	public static function ajax_search()
	{
		$app = $_REQUEST['app'];
		$query = $_REQUEST['query'];
		$options = array();
		$links = array();

		// Only search if a query was provided - don't search for all accounts
		if($query)
		{
			$options['account_type'] = 'accounts';
			$links = Api\Accounts::link_query($query, $options);
		}

		$results = array();
		foreach($links as $id => $name)
		{
			$results[] = array(
				'id' => $id,
				'label' => $name,
				'icon' => Api\Egw::link('/api/avatar.php', array('account_id' => $id))
			);
		}
		$hooks = Api\Hooks::implemented('status-getSearchParticipants');
		foreach($hooks as $app)
		{
			$r = Api\Hooks::process(['location'=>'status-getSearchParticipants', 'app'=>$app], $app);
			$results = array_merge_recursive ($results, $r[$app]);
		}
		usort($results, function ($a, $b) use ($query) {
			$a_label = is_array($a["label"]) ? $a["label"]["label"] : $a["label"];
			$b_label = is_array($b["label"]) ? $b["label"]["label"] : $b["label"];

		    similar_text($query, $a_label, $percent_a);
		    similar_text($query, $b_label, $percent_b);
		    return $percent_a === $percent_b ? 0 : ($percent_a > $percent_b ? -1 : 1);
		});

		 // switch regular JSON response handling off
		Api\Json\Request::isJSONRequest(false);

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($results);
		exit;
	}

}
