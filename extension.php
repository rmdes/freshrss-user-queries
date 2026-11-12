<?php
declare(strict_types=1);

/**
 * Lists, runs and manages saved user queries over /api/misc.php?ext=UserQueries.
 *
 * Auth: the Google Reader API header (`Authorization: GoogleLogin auth=<user>/<token>`),
 * validated exactly like p/api/greader.php does.
 *
 * GET
 * - no params: {"queries": [<query>...]}, each with its id and share links
 * - `name=<query name>` (+ optional nb, offset, search): the query's articles in Google Reader
 *   stream format, applied the same way as p/api/query.php (works for unshared queries too)
 *
 * POST (form or JSON body)
 * - `action=create`: name, category (folder name) or get, search, state, order, description,
 *   share_rss, share_opml. Saved like the UI's "bookmark query".
 * - `action=update`: id or (unique) name, then any field above, plus new_name
 * - `action=delete`: id or (unique) name
 */
final class UserQueriesExtension extends Minz_Extension {
	private const STATES = [
		'read' => FreshRSS_Entry::STATE_READ,
		'unread' => FreshRSS_Entry::STATE_NOT_READ,
		'favorite' => FreshRSS_Entry::STATE_FAVORITE,
		'not_favorite' => FreshRSS_Entry::STATE_NOT_FAVORITE,
	];

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerHook(Minz_HookType::ApiMisc, [$this, 'handleApi']);
	}

	public function handleApi(): void {
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		// misc.php does not load $_GET into Minz_Request (query.php does); also decodes a JSON body into $_POST.
		Minz_Request::init();
		if (self::authenticatedUser() === '') {
			self::fail(401, 'Unauthorized');
		}
		/** @var array<string,mixed> $input */
		$input = array_merge($_GET, $_POST);
		$action = is_string($input['action'] ?? null) ? $input['action'] : '';
		if ($action !== '') {
			if (!Minz_Request::isPost()) {
				self::fail(405, "Use POST for action={$action}");
			}
			match ($action) {
				'create' => self::createQuery($input),
				'update' => self::updateQuery($input),
				'delete' => self::deleteQuery($input),
				default => self::fail(400, "Unknown action '{$action}'"),
			};
			return;
		}
		$name = Minz_Request::paramString('name', plaintext: true);
		if ($name === '') {
			self::listQueries();
		} else {
			self::runQuery($name);
		}
	}

	private static function listQueries(): void {
		$queries = [];
		foreach (FreshRSS_Context::userConf()->queries as $id => $raw) {
			$queries[] = self::describe((int)$id, $raw);
		}
		self::output(['queries' => $queries]);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private static function describe(int $id, array $raw): array {
		$query = new FreshRSS_UserQuery($raw, FreshRSS_Context::categories(), FreshRSS_Context::labels());
		$urls = [
			'html' => $query->sharedUrlHtml(false),
			'rss' => $query->sharedUrlRss(false),
			'greader' => $query->sharedUrlGreader(false),
			'opml' => $query->sharedUrlOpml(false),
		];
		return [
			'id' => $id,
			'name' => $query->getName(),
			'description' => $query->getDescription(),
			'get' => $query->getGet(),
			'get_name' => $query->getGetName(),
			'search' => $query->getSearch()->toString(),
			'state' => $query->getState(),
			'order' => $query->getOrder(),
			'shared_rss' => $query->shareRss(),
			'shared_opml' => $query->shareOpml(),
			'urls' => array_map(fn(string $url): ?string => $url === '' ? null : $url, $urls),
		];
	}

	private static function runQuery(string $name): void {
		$query = null;
		foreach (FreshRSS_Context::userConf()->queries as $raw) {
			if (($raw['name'] ?? '') === $name) {
				$query = new FreshRSS_UserQuery($raw, FreshRSS_Context::categories(), FreshRSS_Context::labels());
				break;
			}
		}
		if ($query === null) {
			self::fail(404, 'User query not found');
		}

		// Same request shaping as p/api/query.php in FreshRSS 1.30.x
		Minz_Request::_param('get', $query->getGet());
		Minz_Request::_param('order', $query->getOrder());
		if (method_exists($query, 'getSort')) {	// Newer FreshRSS only
			Minz_Request::_param('sort', $query->getSort());
		}
		Minz_Request::_param('state', (string)$query->getState());

		$search = $query->getSearch()->toString();
		try {
			// No references to other user queries in the extra search, like query.php
			$userSearch = new FreshRSS_BooleanSearch(Minz_Request::paramString('search', plaintext: true), 0, 'AND', allowUserQueries: false);
		} catch (Minz_Exception $e) {
			self::fail(400, $e->getMessage());
		}
		if ($userSearch->toString() !== '') {
			// Parenthesised and ANDed, so the extra search can only narrow the query
			$search = $search === '' ? $userSearch->toString() : $search . ' (' . $userSearch->toString() . ')';
		}
		Minz_Request::_param('search', $search);

		try {
			FreshRSS_Context::updateUsingRequest(false);
			$items = [];
			foreach (FreshRSS_index_Controller::listEntriesByContext() as $entry) {
				$entry->_feed(FreshRSS_Category::findFeed(FreshRSS_Context::categories(), $entry->feedId()));
				$items[] = $entry->toGReader('freshrss');
			}
		} catch (Minz_Exception $e) {
			self::fail(400, 'Bad user query: ' . $e->getMessage());
		}

		self::output([
			'id' => 'user/-/state/org.freshrss/query/' . rawurlencode($query->getName()),
			'title' => $query->getName(),
			'items' => $items,
		]);
	}

	/** @param array<string,mixed> $input */
	private static function createQuery(array $input): void {
		$name = self::stringParam($input, 'name');
		if ($name === '') {
			self::fail(400, 'name is required');
		}
		$queries = FreshRSS_Context::userConf()->queries;
		self::assertNameFree($queries, $name, null);

		$raw = ['get' => 'a'];
		self::applyFields($raw, $input);
		$raw['token'] = FreshRSS_UserQuery::generateToken($name);
		$raw['name'] = $name;

		$id = empty($queries) ? 0 : (max(array_keys($queries)) + 1);
		self::save($queries, $id, $raw);
	}

	/** @param array<string,mixed> $input */
	private static function updateQuery(array $input): void {
		$queries = FreshRSS_Context::userConf()->queries;
		$id = self::findId($queries, $input);
		$raw = $queries[$id];

		$newName = self::stringParam($input, 'new_name');
		if ($newName !== '') {
			self::assertNameFree($queries, $newName, $id);
			$raw['name'] = $newName;
		}
		self::applyFields($raw, $input);
		if (empty($raw['token'])) {
			$raw['token'] = FreshRSS_UserQuery::generateToken((string)($raw['name'] ?? ''));
		}
		self::save($queries, $id, $raw);
	}

	/** @param array<string,mixed> $input */
	private static function deleteQuery(array $input): void {
		$queries = FreshRSS_Context::userConf()->queries;
		$id = self::findId($queries, $input);
		$name = (string)($queries[$id]['name'] ?? '');
		unset($queries[$id]);
		FreshRSS_Context::userConf()->queries = $queries;
		if (!FreshRSS_Context::userConf()->save()) {
			self::fail(500, 'Could not save user configuration');
		}
		self::output(['deleted' => ['id' => $id, 'name' => $name]]);
	}

	/**
	 * Applies the editable fields present in $input onto $raw, with the UI's normalisations.
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $input
	 */
	private static function applyFields(array &$raw, array $input): void {
		if (array_key_exists('category', $input)) {
			$category = self::stringParam($input, 'category');
			$found = null;
			foreach (FreshRSS_Context::categories() as $cat) {
				if ($cat->name() === $category) {
					$found = $cat;
					break;
				}
			}
			if ($found === null) {
				self::fail(404, "Category '{$category}' not found");
			}
			$raw['get'] = 'c_' . $found->id();
		} elseif (array_key_exists('get', $input)) {
			$raw['get'] = self::stringParam($input, 'get') ?: 'a';
		}
		if (array_key_exists('search', $input)) {
			// Plain text, like the UI stores it
			$raw['search'] = htmlspecialchars_decode(self::stringParam($input, 'search'), ENT_QUOTES);
		}
		if (array_key_exists('state', $input)) {
			$raw['state'] = self::stateParam($input['state']);
		}
		if (array_key_exists('order', $input)) {
			$order = strtoupper(self::stringParam($input, 'order'));
			if (!in_array($order, ['', 'ASC', 'DESC'], true)) {
				self::fail(400, 'order must be ASC or DESC');
			}
			$raw['order'] = $order;
		}
		if (array_key_exists('description', $input)) {
			$raw['description'] = self::stringParam($input, 'description');
		}
		if (array_key_exists('share_rss', $input)) {
			$raw['shareRss'] = self::boolParam($input['share_rss']);
		}
		if (array_key_exists('share_opml', $input)) {
			$raw['shareOpml'] = self::boolParam($input['share_opml']);
		}
	}

	/**
	 * Normalises through FreshRSS_UserQuery like the UI, stores, and outputs the saved query.
	 * @param array<int,array<string,mixed>> $queries
	 * @param array<string,mixed> $raw
	 */
	private static function save(array $queries, int $id, array $raw): void {
		// Internal link to the query view, built like configureController does
		$raw['url'] = Minz_Url::display(['params' => array_intersect_key($raw,
			array_flip(['get', 'order', 'search', 'state', 'token']))]);
		$queries[$id] = (new FreshRSS_UserQuery($raw, FreshRSS_Context::categories(), FreshRSS_Context::labels()))->toArray();
		FreshRSS_Context::userConf()->queries = $queries;
		if (!FreshRSS_Context::userConf()->save()) {
			self::fail(500, 'Could not save user configuration');
		}
		self::output(['query' => self::describe($id, $queries[$id])]);
	}

	/**
	 * @param array<int,array<string,mixed>> $queries
	 * @param array<string,mixed> $input
	 */
	private static function findId(array $queries, array $input): int {
		$id = $input['id'] ?? null;
		if (is_int($id) || (is_string($id) && ctype_digit($id))) {
			if (!isset($queries[(int)$id])) {
				self::fail(404, 'User query not found');
			}
			return (int)$id;
		}
		$name = self::stringParam($input, 'name');
		$ids = array_keys(array_filter($queries, fn(array $q): bool => ($q['name'] ?? '') === $name));
		if ($name === '' || count($ids) === 0) {
			self::fail(404, 'User query not found');
		}
		if (count($ids) > 1) {
			self::fail(409, "Several user queries are named '{$name}' (ids " . implode(', ', $ids) . '); use id');
		}
		return (int)$ids[0];
	}

	/** @param array<int,array<string,mixed>> $queries */
	private static function assertNameFree(array $queries, string $name, ?int $exceptId): void {
		foreach ($queries as $id => $query) {
			if ($id !== $exceptId && ($query['name'] ?? '') === $name) {
				self::fail(409, "A user query named '{$name}' already exists (id {$id})");
			}
		}
	}

	/** @param array<string,mixed> $input */
	private static function stringParam(array $input, string $key): string {
		$value = $input[$key] ?? '';
		return is_string($value) ? trim($value) : '';
	}

	private static function boolParam(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
	}

	/** State as an int bitmask, or a list of read/unread/favorite/not_favorite. */
	private static function stateParam(mixed $value): int {
		if (is_int($value) || (is_string($value) && ctype_digit($value))) {
			return (int)$value;
		}
		$names = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
		$state = 0;
		foreach ($names as $name) {
			$name = is_string($name) ? trim($name) : '';
			if (!isset(self::STATES[$name])) {
				self::fail(400, 'state must be an integer or a list of: ' . implode(', ', array_keys(self::STATES)));
			}
			$state |= self::STATES[$name];
		}
		return $state;
	}

	/** Mirrors authorizationToUser() in p/api/greader.php. Returns '' when not authenticated. */
	private static function authenticatedUser(): string {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (!is_string($header) || $header === '') {
			$header = '';
			foreach (function_exists('getallheaders') ? getallheaders() : [] as $key => $value) {
				if (is_string($key) && strcasecmp($key, 'Authorization') === 0 && is_string($value)) {
					$header = $value;
				}
			}
		}
		// "GoogleLogin auth=<user>/<token>": PHP turns the space into "_" when parsing
		parse_str($header, $pairs);
		$auth = $pairs['GoogleLogin_auth'] ?? '';
		$parts = is_string($auth) ? explode('/', $auth, 2) : [];
		if (count($parts) !== 2 || !FreshRSS_user_Controller::checkUsername($parts[0])) {
			return '';
		}
		[$user, $token] = $parts;
		FreshRSS_Context::initUser($user);
		if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::userConf()->enabled ||
			FreshRSS_Context::userConf()->apiPasswordHash === '') {
			return '';
		}
		$expected = sha1(FreshRSS_Context::systemConf()->salt . $user . FreshRSS_Context::userConf()->apiPasswordHash);
		return hash_equals($expected, $token) ? $user : '';
	}

	/** @param array<string,mixed> $data */
	private static function output(array $data): void {
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
	}

	private static function fail(int $code, string $message): never {
		http_response_code($code);
		self::output(['error' => $message]);
		exit();
	}
}
