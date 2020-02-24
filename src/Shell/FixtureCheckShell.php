<?php

namespace Psa\FixtureCheck\Shell;

use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Database\Exception;
use Cake\Database\Schema\Collection;
use Cake\Datasource\ConnectionManager;
use Cake\Error\Debugger;
use Cake\Filesystem\Folder;
use Cake\ORM\Table;

/**
 * A CakePHP Shell to compare fixtures against a live DB
 *
 * Examples:
 *
 * ```
 * FixtureCheck -p Users -f Users,Roles -c live
 * ```
 *
 * The example above will check for fixtures named Users and Roles in the plugin
 * Users against the live connection.
 *
 * @author Florian Krämer
 * @author Mark Scherer
 * @copyright PSA Publishers Ltd
 * @license MIT
 */
class FixtureCheckShell extends Shell
{

	/**
	 * Configuration read from Configure
	 *
	 * @var array
	 */
	protected $_config = [
		'ignoreClasses' => [],
	];

	/**
	 * Flag if differences where detected
	 *
	 * @var bool
	 */
	protected $_issuesFound = false;

	/**
	 * @var int
	 */
	protected $_totalDifferencesDetected = 0;

	/**
	 * @inheritDoc
	 */
	public function initialize()
	{
		parent::initialize();
		$this->_config = (array)Configure::read('FixtureCheck') + $this->_config;
	}

	/**
	 * @return void
	 */
	public function main()
	{
		$this->diff();
	}

	/**
	 * @return void
	 */
	public function diff()
	{
		$fixtures = $this->_getFixtures();
		$connection = ConnectionManager::get($this->param('connection'));

		$collection = new Collection($connection);
		//$liveTables = $collection->listTables();

		foreach ($fixtures as $fixture) {
			//$className = App::className($fixture, 'Test/Fixture', 'Fixture');
			$fixtureClass = 'App\Test\Fixture\\' . $fixture;
			if (!class_exists($fixtureClass)) {
				$this->err(sprintf('Fixture %s does not exist.', $fixtureClass));
				continue;
			}

			if (in_array($fixtureClass, $this->_config['ignoreClasses'])) {
				continue;
			}

			$fixture = new $fixtureClass();
			$fixtureFields = $fixture->fields;
			//$tablesWithFixtures = [];

			//TODO: Add indexes and constraints check, as well
			unset(
				$fixtureFields['_options'],
				$fixtureFields['_constraints'],
				$fixtureFields['_indexes']
			);

			try {
				$table = new Table([
					'table' => $fixture->table,
					'connection' => $connection
				]);

				$this->info(sprintf('Comparing `%s` with table `%s`', $fixtureClass, $fixture->table));

				$liveFields = [];
				$columns = $table->schema()->columns();
				foreach ($columns as $column) {
					$liveFields[$column] = $table->schema()->column($column);
				}

				ksort($fixtureFields);
				ksort($liveFields);

				$this->_compareFieldPresence($fixtureFields, $liveFields, $fixtureClass);
				$this->_compareFields($fixtureFields, $liveFields);
			} catch(Exception $e) {
				$this->err($e->getMessage());
			}
		}

		if (!empty($this->_config['ignoreClasses'])) {
			$this->info('Ignored fixture classes:');
			foreach ($this->_config['ignoreClasses'] as $ignoredFixture) {
				$this->out(' * ' . $ignoredFixture);
			}
			$this->out($this->nl(0));
		}

		if ($this->_issuesFound) {
			$this->abort(sprintf(
				'%d differences detected, check your fixtures and DB.',
				$this->_totalDifferencesDetected
			));
		}
	}

	/**
	 * Compare the fields present.
	 *
	 * @param array $fixtureFields The fixtures fields array.
	 * @param array $liveFields The live DB fields
	 * @return void
	 */
	public function _compareFields($fixtureFields, $liveFields)
	{
		// Warn only about relevant fields
		$list = [
			'autoIncrement',
			'default',
			'length',
			'null',
			'precision',
			'type',
			'unsigned',
		];

		$errors = [];
		foreach ($fixtureFields as $fieldName => $fixtureField) {
			if (!isset($liveFields[$fieldName])) {
				$this->out('Field ' . $fieldName . ' is missing from the live DB!');
				continue;
			}

			$liveField = $liveFields[$fieldName];

			// Cast it to string because the default value returned from the DB
			// seems to be always a string :(
			if (isset($fixtureField['default'])) {
				$fixtureField['default'] = (string)$fixtureField['default'];
			}

			foreach ($fixtureField as $key => $value) {
				if (!in_array($key, $list)) {
					continue;
				}

				if (!isset($liveField[$key]) && $value !== null) {
					$errors[] = ' * ' . sprintf(
						'Field attribute `%s` is missing from the live DB!',
						$fieldName . ':' . $key
					);
					continue;
				}

				if ($key === 'autoIncrement') {
					// @todo unsure how to handle this case.
					continue;
				}

				if (isset($liveField[$key]) && $liveField[$key] !== $value) {
					$errors[] = ' * ' . sprintf(
						'Field `%s` attribute `%s` differs from live DB! (`%s` vs `%s` live)',
						$fieldName,
						$key,
						Debugger::exportVar($value, true),
						Debugger::exportVar($liveField[$key], true)
					);
				}
			}
		}

		if (!$errors) {
			return true;
		}

		$errorCount = count($errors);
		$this->_totalDifferencesDetected += $errorCount;
		$this->warn(sprintf('Found %d attribute mismatches:', $errorCount));

		$this->out($errors);
		$this->_issuesFound = true;
		$this->out($this->nl(0));

		return false;
	}

	/**
	 * Get the fixture files
	 *
	 * @return array
	 */
	protected function _getFixtures()
	{
		$fixtures = $this->_getFixturesFromOptions();
		if ($fixtures) {
			return $fixtures;
		}

		return $this->_getFixtureFiles();
	}

	/**
	 * _getFixturesFromOptions
	 *
	 * @return array|bool
	 */
	protected function _getFixturesFromOptions()
	{
		$fixtureString = $this->param('fixtures');
		if (!empty($fixtureString)) {
			$fixtures = explode(',', $fixtureString);
			foreach ($fixtures as $key => $fixture) {
				$fixtures[$key] = $fixture . 'Fixture';
			}
			return $fixtures;
		}

		return false;
	}

	/**
	 * Gets a list of fixture files.
	 *
	 * @return array Array of fixture files
	 */
	protected function _getFixtureFiles()
	{
		$fixtureFolder = TESTS . 'Fixture' . DS;
		$plugin = $this->param('plugin');
		if ($plugin) {
			$fixtureFolder = Plugin::path($plugin) . 'tests' . DS . 'Fixture' . DS;
		}

		$folder = new Folder((string)$fixtureFolder);
		$content = $folder->read();

		$fixtures = [];
		foreach ($content[1] as $file) {
			$fixture = substr($file, 0, -4);
			if (substr($fixture, -7) !== 'Fixture') {
				continue;
			}
			$fixtures[] = $fixture;
		}

		return $fixtures;
	}

	/**
	 * Compare if the fields are present in the fixtures.
	 *
	 * @param array $one Array one
	 * @param array $two Array two
	 * @param string $fixtureClass Fixture class name.
	 * @param string $message Message to display.
	 * @return bool
	 */
	protected function _doCompareFieldPresence($one, $two, $fixtureClass, $message): bool {
		$diff = array_diff_key($one, $two);
		if (!empty($diff)) {
			$this->warn(sprintf($message, $fixtureClass));
			foreach ($diff as $missingField => $type) {
				$this->out(' * ' . $missingField);
			}
			$this->out($this->nl(0));
			$this->_issuesFound = true;

			return false;
		}

		return true;
	}

	/**
	 * Compare the fields present.
	 *
	 * @param array $fixtureFields The fixtures fields array.
	 * @param array $liveFields The live DB fields
	 * @param string $fixtureClass Fixture class name.
	 * @return bool
	 */
	protected function _compareFieldPresence($fixtureFields, $liveFields, $fixtureClass): bool {

		$message = '%s has fields that are not in the live DB:';
		$result = $this->_doCompareFieldPresence($fixtureFields, $liveFields, $fixtureClass, $message);

		$message = 'Live DB has fields that are not in %s';
		$result2 = $this->_doCompareFieldPresence($liveFields, $fixtureFields, $fixtureClass, $message);

		return $result && $result2;
	}

	/**
	 * @inheritDoc
	 */
	public function getOptionParser()
	{
		return parent::getOptionParser()
			->setDescription('Compare DB and fixture schema columns.')
			->addOption('connection', [
				'short' => 'c',
				'default' => 'default',
				'help' => 'Connection to compare against.'
			])
			->addOption('plugin', [
				'short' => 'p',
				'help' => 'Plugin'
			])
			// @todo implement this
			/*
			->addOption('direction', [
				'short' => 'd',
				'default' => 'both',
				'help' => 'Direction of diff detection: `both`, `fixture` or `db`.'
			])
			*/
			->addOption('fixtures', [
				'help' => 'Fixtures to use.',
				'short' => 'f',
				'default' => null
			]);
	}
}
