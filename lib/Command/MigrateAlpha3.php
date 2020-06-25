<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Command;


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OC\Core\Command\Base;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;


class MigrateAlpha3 extends Base {


	use TArrayTools;


	/** @var IDBConnection */
	private $dbConnection;

	/** @var CoreRequestBuilder */
	private $coreRequestBuilder;

	/** @var CheckService */
	private $checkService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;

	/** @var array */
	private $done = [];


	/** @var array */
	public $tables = [
		'social_a2_actions'       => [
			['id_prim'],
			'social_3_action',
			[
				'actor_id_prim'  => 'PRIM:actor_id',
				'object_id_prim' => 'PRIM:object_id'
			]
		],
		'social_a2_actors'        => [['user_id'], 'social_3_actor', []],
		'social_a2_cache_actors'  => [['id_prim'], 'social_3_cache_actor', []],
		'social_a2_cache_documts' => [['id_prim'], 'social_3_cache_doc', []],
		'social_a2_follows'       => [
			['id_prim'],
			'social_3_follow',
			[
				'actor_id_prim'  => 'PRIM:actor_id',
				'object_id_prim' => 'PRIM:object_id',
				'follow_id_prim' => 'PRIM:follow_id'
			]
		],

		'social_a2_hashtags'      => [['hashtag'], 'social_3_hashtag', []],
		'social_a2_request_queue' => [['id'], 'social_3_req_queue', []],
		'social_a2_stream'        => [
			['id_prim'],
			'social_3_stream',
			[
				'object_id_prim'     => 'PRIM:object_id',
				'in_reply_to_prim'   => 'PRIM:in_reply_to',
				'attributed_to_prim' => 'PRIM:attributed_to',
				'filter_duplicate'   => 'COPY:hidden_on_timeline',
				'hidden_on_timeline' => 'REMOVED:'
			]
		],
		'social_a2_stream_action' => [
			['id'],
			'social_3_stream_act',
			[
				'actor_id_prim'  => 'PRIM:actor_id',
				'stream_id_prim' => 'PRIM:stream_id',
				'_function_'     => 'migrateTableStreamAction'
			]
		],
		'social_a2_stream_queue'  => [['id'], 'social_3_stream_queue', []]
	];


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IDBConnection $dbConnection
	 * @param CoreRequestBuilder $coreRequestBuilder
	 * @param CheckService $checkService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $dbConnection, CoreRequestBuilder $coreRequestBuilder, CheckService $checkService,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();
		$this->dbConnection = $dbConnection;
		$this->checkService = $checkService;
		$this->coreRequestBuilder = $coreRequestBuilder;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:migrate:alpha3')
			 ->setDescription('Trying to migrate old data to Alpha3')
			 ->addOption(
				 'remove-migrated-tables', '', InputOption::VALUE_NONE, 'Remove old table once copy is done'
			 )
			 ->addOption(
				 'force-remove-old-tables', '', InputOption::VALUE_NONE, 'Force remove old tables'
			 );
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$tables = $this->checkTables();

		if ($input->getOption('force-remove-old-tables')) {
			foreach ($tables as $table) {
				$this->dropTable($table);
			}

			return;
		}

		if (empty($tables)) {
			$output->writeln('Nothing to migrate.');

			return;
		}

		$defTables = '';
		if (sizeof($tables) < sizeof($this->tables)) {
			$defTables = ': \'' . implode("', '", $tables) . '\'';
		}

		$output->writeln(
			'Found ' . sizeof($tables) . ' tables to migrate' . $defTables . '.'
		);

		if (!$this->confirmExecute($input, $output)) {
			return;
		}

		$this->done = [];
		$this->migrateTables($output, $tables);

		if ($input->getOption('remove-migrated-tables')) {
			$this->dropDeprecatedTables($input, $output);
		}
	}


	/**
	 * @return string[]
	 */
	private function checkTables(): array {
		$ak = array_keys($this->tables);
		$tables = [];
		foreach ($ak as $k) {
			if ($this->dbConnection->tableExists($k)) {
				$tables[] = $k;
			}
		}

		return $tables;
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool
	 */
	private function confirmExecute(InputInterface $input, OutputInterface $output): bool {
		$helper = $this->getHelper('question');
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>Do you want to migrate data from the old database?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return false;
		}

		return true;
	}


	/**
	 * @param OutputInterface $output
	 * @param array $tables
	 */
	private function migrateTables(OutputInterface $output, array $tables) {
		foreach ($tables as $table) {
			try {
				$this->migrateTable($output, $table);
				$output->writeln('Migration of \'<comment>' . $table . '</comment>\': <info>ok</info>');
			} catch (Exception $e) {
				$output->writeln(
					'Migration of \'<comment>' . $table . '</comment>\': <error>fail</error> - '
					. $e->getMessage()
				);
			}
		}

	}


	/**
	 * @param OutputInterface $output
	 * @param string $table
	 */
	private function migrateTable(OutputInterface $output, string $table) {
		$output->writeln('');
		$output->writeln('Retrieving data from \'' . $table . '\'.');
		$fullContent = $this->getContentFromTable($table);

		$output->write('Found ' . count($fullContent) . ' entries');
		$m = $copied = 0;
		foreach ($fullContent as $entry) {
			if ($m % 50 === 0) {
				$output->write('.');
			}

			if ($this->migrateEntry($table, $entry)) {
				$copied++;
			}

			$m++;
		}

		$output->writeln(' <info>' . $copied . ' copied</info>');

		$this->done[] = $table;
	}


	/**
	 * @param string $table
	 *
	 * @return array
	 */
	private function getContentFromTable(string $table): array {
		$qb = $this->dbConnection->getQueryBuilder();

		$qb->select('*')
		   ->from($table);

		$entries = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$entries[] = $data;
		}
		$cursor->closeCursor();

		return $entries;
	}


	/**
	 * @param string $table
	 * @param $entry
	 *
	 * @return bool
	 */
	private function migrateEntry(string $table, $entry): bool {
		if (!$this->checkUnique($table, $entry)) {
			return false;
		}

		list(, $destTable, $destDefault) = $this->tables[$table];

		$qb = $this->dbConnection->getQueryBuilder();

		$qb->insert($destTable);
		$ak = array_merge(array_keys($entry), array_keys($destDefault));
		foreach ($ak as $k) {
			if ($k === '_function_') {
				continue;
			}

			$value = '';

			try {
				if ($this->get($k, $entry, '') !== '') {
					$this->manageDefault($qb, $this->get($k, $destDefault), $entry);
					$value = $entry[$k];
				} else if (array_key_exists($k, $destDefault)) {
					$value = $this->manageDefault($qb, $destDefault[$k], $entry);
				}
			} catch (Exception $e) {
				continue;
			}

			if ($value !== '') {
				$qb->setValue($k, $qb->createNamedParameter($value));
			}
		}

		if (array_key_exists('_function_', $destDefault)) {
			call_user_func_array([$this, $destDefault['_function_']], [$qb, $entry]);
		}

		$qb->execute();

		return true;
	}


	/**
	 * @param string $table
	 * @param $entry
	 *
	 * @return bool
	 */
	private function checkUnique(string $table, $entry): bool {
		list($unique, $destTable) = $this->tables[$table];

		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
		   ->from($destTable);

		$expr = $qb->expr();
		$andX = $expr->andX();
		foreach ($unique as $f) {
			$andX->add($expr->eq($f, $qb->createNamedParameter($entry[$f])));
		}
		$qb->andWhere($andX);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return true;
		}

		return false;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $default
	 * @param array $entry
	 *
	 * @return IParameter|string
	 * @throws Exception
	 */
	private function manageDefault(IQueryBuilder $qb, string $default, array $entry) {
		if ($default === '') {
			return '';
		}

		if (!strpos($default, ':')) {
			return $qb->createNamedParameter($default);
		}

		list($k, $v) = explode(':', $default, 2);
		switch ($k) {
			case 'COPY':
				return $this->get($v, $entry, '');

			case 'PRIM':
				if ($this->get($v, $entry, '') === '') {
					return '';
				}

				return hash('sha512', $entry[$v]);

			case 'REMOVED':
				throw new Exception();
		}

		return '';
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	private function dropDeprecatedTables(InputInterface $input, OutputInterface $output) {
		$helper = $this->getHelper('question');
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>You migrate ' . count($this->done) . ' table. Do you want to remove them ?</info> (y/N) ',
			false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return;
		}

		foreach ($this->done as $table) {
			$this->dropTable($table);
		}
	}


	/**
	 * @param string $table
	 */
	private function dropTable(string $table) {
		$this->dbConnection->dropTable($table);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param array $entry
	 */
	public function migrateTableStreamAction(IQueryBuilder $qb, array $entry) {
		$values = json_decode($entry['values'], true);
		if ($values === null) {
			return;
		}

		$liked = ($this->getBool('liked', $values)) ? '1' : '0';
		$boosted = ($this->getBool('boosted', $values)) ? '1' : '0';

		$qb->setValue('liked', $qb->createNamedParameter($liked));
		$qb->setValue('boosted', $qb->createNamedParameter($boosted));
	}

}

