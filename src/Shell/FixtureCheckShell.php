<?php
namespace Psa\FixtureCheck\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Database\Schema\Collection;
use Cake\Datasource\ConnectionManager;
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
 * @copyright PSA Publishers Ltd
 * @license MIT
 */
class FixtureCheckShell extends Shell {

	/**
	 * @var array
	 * Configuration read from Configure
	 *
	 * @param array
	 */
	protected $_config = [];

	/**
	 * Flag if differences where detected
	 *
	 * @var bool
	 */
	protected $_issuesFound = false;

	/**
	 * @inheritDoc
	 */
	public function initialize() {
		parent::initialize();
		$this->_config = (array)Configure::read('FixtureCheck');
	}

	/**
	 * @return void
	 */
	public function main() {
		$this->diff();
	}

	/**
	 * @return void
	 */
	public function diff() {
		$fixtures = $this->_getFixtures();
		$connection = ConnectionManager::get($this->param('connection'));

		$collection = new Collection($connection);
		$liveTables = $collection->listTables();

		foreach ($fixtures as $fixture) {
			$fixtureClass = '\App\Test\Fixture\\' . $fixture;
			if (!class_exists($fixtureClass)) {
				$this->err(sprintf('Fixture %s does not exist.', $fixtureClass));
				continue;
			}

			if (in_array($fixtureClass, $this->_config['ignoreClasses'])) {
				continue;
			}

			$fixture = new $fixtureClass();
			$fixtureFields = $fixture->fields;
			$tablesWithFixtures = [];

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

				$this->info(sprintf('Comparing %s with table %s', $fixtureClass, $fixture->table));

				$liveFields = [];
				$columns = $table->schema()->columns();
				foreach ($columns as $column) {
					$liveFields[$column] = $table->schema()->column($column);
				}

				ksort($fixtureFields);
				ksort($liveFields);

				$this->_compareFieldPresence($fixtureFields, $liveFields, $fixtureClass);
				$this->_compareFields($fixtureFields, $liveFields);
			} catch(\Cake\Database\Exception $e) {
				$this->err($e->getMessage());
			}
		}

		if (!empty($this->_config['ignoreClasses'])) {
			$this->info('Ignored fixture classes:');
			foreach ($this->_config['ignoreClasses'] as $ignoredFixture) {
				$this->out(' * ' . $ignoredFixture);
			}
		}

		if ($this->_issuesFound) {
			$this->abort('Differences detected, check your fixtures and DB.');
		}
	}

	/**
	 * Compare the fields present.
	 *
	 * @param array $fixtureFields The fixtures fields array.
	 * @param array $liveFields The live DB fields
	 * @return void
	 */
	public function _compareFields($fixtureFields, $liveFields) {
		$liveFieldKeys = array_keys($liveFields);
		foreach ($fixtureFields as $fieldName => $fixtureField) {
			if (!in_array($fixtureField, $liveFieldKeys)) {
				unset($fixtureFields[$fieldName]);
				continue;
			}

			$liveField = $liveFields[$fieldName];
			ksort($fixtureField);
			ksort($liveField);

			foreach ($fixtureField as $key => $value) {
				if (!array_key_exists($key, $liveField)) {
					//$this->out('Field ' . $fieldName . ':' . $key . ' is missing from the live DB!');
					continue;
				}
				if ($liveField[$key] !== $value) {
					$this->out('Field ' . $fieldName . ':' . $key . ' differs from live DB!');
				}
			}
		}
	}

	/**
	 * Get the fixture files
	 *
	 * @return array
	 */
	protected function _getFixtures() {
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
	protected function _getFixturesFromOptions() {
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
	protected function _getFixtureFiles() {
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
	 * @return void
	 */
	protected function _doCompareFieldPresence($one, $two, $fixtureClass, $message) {
		$diff = array_diff_key($one, $two);
		if (!empty($diff)) {
			$this->warn(sprintf($message, $fixtureClass));
			foreach ($diff as $missingField => $type) {
				$this->out(' * ' . $missingField);
			}
			$this->out($this->nl(0));
			$this->_issuesFound = true;
		}
	}

	/**
	 * Compare the fields present.
	 *
	 * @param array $fixtureFields The fixtures fields array.
	 * @param array $liveFields The live DB fields
	 * @param string $fixtureClass Fixture class name.
	 * @return void
	 */
	protected function _compareFieldPresence($fixtureFields, $liveFields, $fixtureClass) {
		$message = '%s has fields that are not in the live DB:';
		$this->_doCompareFieldPresence($fixtureFields, $liveFields, $fixtureClass, $message);

		$message = 'Live DB has fields that are not in %s';
		$this->_doCompareFieldPresence($liveFields, $fixtureFields, $fixtureClass, $message);
	}

	/**
	 * @inheritDoc
	 */
	public function getOptionParser() {
		return parent::getOptionParser()
			->description('Compare DB and fixture schema columns.')
			->addOption('connection', [
				'short' => 'c',
				'default' => 'default',
				'help' => 'Connection to compare against'
			])
			->addOption('plugin', [
				'short' => 'p',
				'help' => 'Plugin'
			])
			// @todo implement this
			->addOption('direction', [
				'short' => 'd',
				'default' => 'both',
				'help' => 'Direction of diff detection: `both`, `fixture` or `db`'
			])
			->addOption('fixtures', [
				'short' => 'f',
				'default' => null
			]);
	}

}
