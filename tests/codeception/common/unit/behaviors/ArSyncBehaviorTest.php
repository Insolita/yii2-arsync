<?php
namespace tests\codeception\common\unit\behaviors;


use Codeception\Specify;
use Codeception\Util\Debug;
use Codeception\Verify;
use insolita\arsync\ArSyncBehavior;
use tests\codeception\common\unit\fixtures\DbMaster;
use tests\codeception\common\unit\fixtures\DbSlave;
use tests\codeception\common\unit\fixtures\RedisMaster;
use tests\codeception\common\unit\fixtures\RedisSlave;
use tests\codeception\common\unit\TestCase;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\InvalidValueException;
use yii\db\Connection;
use yii\redis\ActiveRecord;

class ArSyncBehaviorTest extends TestCase
{
	use Specify;
	/**
	 * @var \tests\codeception\common\UnitTester
	 */
	protected $tester;

	/**@var DbMaster */
	protected $masterModel;

	/**@var DbSlave * */
	protected $slaveModel;

	/**@var RedisMaster */
	protected $masterRedisModel;

	/**@var RedisSlave * */
	protected $slaveRedisModel;


	public function setUp()
	{
		parent::setUp();
		//$this->specifyConfig()->deepClone(false);
		$this->masterModel = new DbMaster();
		$this->masterModel->detachBehavior('ArSyncBehavior');
		$this->slaveModel = new DbSlave();
		$this->slaveModel->detachBehavior('ArSyncBehavior');

		$this->masterRedisModel = new RedisMaster();
		$this->masterRedisModel->detachBehavior('ArSyncBehavior');
		$this->slaveRedisModel = new RedisSlave();
		$this->slaveRedisModel->detachBehavior('ArSyncBehavior');

		$this->clearModel($this->masterRedisModel);
		$this->clearModel($this->slaveRedisModel);
		$this->clearModel($this->masterModel);
		$this->clearModel($this->slaveModel);

	}

	protected function clearModel($model)
	{
		call_user_func([$model, 'deleteAll']);
		/**@var Connection $db * */
		$db = $model::getDb();
		if ($db instanceof Connection and $model->hasMethod('tableName', false))
		{
			$db->createCommand()->truncateTable($model::tableName());
			if ($db->getDriverName() == 'pgsql')
			{
				$db->createCommand()->resetSequence($model::tableName(), 1);
			}
		}
	}

	public function testPrepareFieldMap()
	{
		$beh = new ArSyncBehavior();
		$this->specify('with simple hash fieldMap', function() use ($beh)
		{
			$prepared = $this->callPrivateMethod($beh, 'prepareFieldMap', [['foo' => 'foo', 'bar' => 'baz']]);
			verify($prepared)->equals(['foo' => 'foo', 'bar' => 'baz']);
		}, ['is_deep' => false]);

		$this->specify('with indexed array fieldMap', function() use ($beh)
		{
			$prepared = $this->callPrivateMethod($beh, 'prepareFieldMap', [['foo', 'bar']]);
			verify('expect associative', $prepared)->equals(['foo' => 'foo', 'bar' => 'bar']);
		}, ['is_deep' => false]);

		$this->specify('with associative array with closure fieldMap', function() use ($beh)
		{
			$prepared = $this->callPrivateMethod($beh, 'prepareFieldMap', [
				[
					'foo' => 'foo',
					'bar' => function()
					{
						return
							'baz';
					},
				],
			]);
			verify('expect closure success', $prepared)->equals([
				'foo' => 'foo',
				'bar' => function()
				{
					return 'baz';
				},
			]);
		}, ['is_deep' => false]);

		$this->specify('with incorrect fieldMap', function() use ($beh)
		{
			$this->expectException(InvalidConfigException::class);
			$this->callPrivateMethod($beh, 'prepareFieldMap', [['foo' => 'foo', 'bar', 'baz']]);
		}, ['is_deep' => false]);
	}

	public function testInit()
	{
		$beh = new ArSyncBehavior(['fieldMap' => ['foo', 'bar']]);
		verify('fieldMap prepared on init', $beh->fieldMap)->equals(['foo' => 'foo', 'bar' => 'bar']);
	}

	public function testPopulateSlave()
	{

		$this->specify('population with simple hash', function()
		{
			$slave = $this->slaveModel;
			$master = $this->masterModel;
			$attrs = ['id' => 100500, 'baz' => 1020, 'bar' => 'foo', 'foo' => '127.0.0.1', 'name' => 'Testy'];
			$master->setAttributes($attrs, false);
			$beh = new ArSyncBehavior([
				'fieldMap' => ['id', 'foo', 'baz'],
			]);
			$popSlave = $this->callPrivateMethod($beh, 'populateSlave', [$master, $slave, $beh->fieldMap]);
			verify('instance correct', $popSlave)->isInstanceOf($slave::className());
			verify('attributes populated', $popSlave->id)->equals($attrs['id']);
			verify('attributes populated', $popSlave->foo)->equals($attrs['foo']);
			verify('attributes populated', $popSlave->baz)->equals($attrs['baz']);
			verify('attributes, not defined in fieldMap - not synced', $popSlave->bar)->notEquals($attrs['bar']);
		}, ['deep_copy' => false]);

		$this->specify('population with closure', function()
		{
			$slave = $this->slaveModel;
			$master = $this->masterModel;
			$attrs = ['id' => 100500, 'baz' => 1020, 'bar' => '127.0.0.1', 'name' => 'Testy'];
			$master->setAttributes($attrs, false);
			$beh = new ArSyncBehavior([
				'fieldMap' => [
					'id'    => 'id',
					'title' => 'name',
					'baz'   => function($master)
					{
						return $master->baz - 111;
					},
				],
			]);
			$popSlave = $this->callPrivateMethod($beh, 'populateSlave', [$master, $slave, $beh->fieldMap]);
			verify('instance correct', $popSlave)->isInstanceOf($slave::className());
			verify('attributes populated', $popSlave->baz)->equals(909);
			verify('attributes populated', $popSlave->title)->equals($attrs['name']);

		}, ['deep_copy' => false]);
	}

	public function testSyncOnNewRecord()
	{
		$slave = $this->slaveModel;
		$slaveMock = $this->getMockBuilder($slave::className())->setMethods(['save'])->getMock();
		$slaveMock->scenario = 'sync';
		$slaveMock->expects($this->once())->method('save')->willReturn(false);
		verify_not($slaveMock->id, 'empty on initials');
		verify_not($slaveMock->title, 'empty on initials');
		verify_not($slaveMock->bar, 'empty on initials');
		verify_not($slaveMock->baz, 'empty on initials');

		$master = $this->masterModel;
		$attrs = ['id' => 100500, 'baz' => 1020, 'bar' => '127.0.0.1', 'name' => 'Testy'];
		$master->setAttributes($attrs, false);
		$master->setIsNewRecord(true);
		$behMock = $this->getMockBuilder(ArSyncBehavior::className())->setMethods(['createNewSlave'])
			->setConstructorArgs([
				[
					'owner'         => $master,
					'slaveScenario' => 'sync',
					'fieldMap'      => [
						'id'    => 'id',
						'title' => 'name',
						'foo'   => 'bar',
						'baz'   => function($master)
						{
							return $master->baz - 111;
						},
					],
				],
			])->getMock();
		$behMock->expects($this->once())->method('createNewSlave')->willReturn($slaveMock);
		$behMock->sync();
		verify('attributes populated', $slaveMock->baz)->equals(909);
		verify('attributes populated', $slaveMock->title)->equals($attrs['name']);
		verify('attributes populated', $slaveMock->foo)->equals($attrs['bar']);
	}

	public function testSyncOnNotSupportedScenraio()
	{
		$slave = $this->slaveModel;
		$slaveMock = $this->getMockBuilder($slave::className())->setMethods(['save', 'delete'])->getMock();

		//If scenario not supported - slave not saved
		$slaveMock->expects($this->never())->method('save')->willReturn(false);
		$slaveMock->expects($this->never())->method('delete')->willReturn(false);

		$master = $this->masterModel;
		$master->scenario = 'lalala';
		$attrs = ['id' => 100500, 'baz' => 1020, 'bar' => '127.0.0.1', 'name' => 'Testy'];
		$master->setAttributes($attrs, false);
		$behMock = $this->getMockBuilder(ArSyncBehavior::className())->setMethods(['createNewSlave', 'findSlaveByPk'])
			->setConstructorArgs([
				[
					'owner'           => $master,
					'saveScenarios'   => ['insert'],
					'deleteScenarios' => ['delete'],
					'slaveScenario'   => 'sync',
					'fieldMap'        => [
						'id'    => 'id',
						'title' => 'name',
						'foo'   => 'bar',
						'baz'   => function($master)
						{
							return $master->baz - 111;
						},
					],
				],
			])->getMock();
		$behMock->expects($this->never())->method('createNewSlave');
		$behMock->sync();
		verify_not($slaveMock->id, 'not populated');
		verify_not($slaveMock->title, 'not populated');
		verify_not($slaveMock->bar, 'not populated');
		verify_not($slaveMock->baz, 'not populated');
		$behMock->expects($this->never())->method('findSlaveByPk');
		$behMock->syncDelete();
	}

	public function testSyncOnExistedRecord()
	{
		$slave = $this->slaveModel;
		$slaveMock = $this->getMockBuilder($slave::className())->setMethods(['save', 'delete'])->getMock();
		$slaveMock->scenario = 'sync';
		$sattrs = ['id' => 100500, 'baz' => 777, 'bar' => 'qwerty', 'title' => 'FooBar'];
		$slaveMock->setAttributes($sattrs, false);

		$slaveMock->expects($this->once())->method('save')->willReturn(false);
		//$slaveMock->expects($this->once())->method('delete')->willReturn(false);

		$master = $this->masterModel;
		$attrs = ['id' => 100500, 'baz' => 1020, 'bar' => '127.0.0.1', 'name' => 'Testy'];
		$master->setAttributes($attrs, false);
		$master->setIsNewRecord(false);
		$behMock = $this->getMockBuilder(ArSyncBehavior::className())->setMethods([
			'createNewSlave',
			'findSlaveByPk',
			'deleteSlave',
		])
			->setConstructorArgs([
				[
					'owner'         => $master,
					'slaveScenario' => 'sync',
					'fieldMap'      => [
						'id'    => 'id',
						'title' => 'name',
						'foo'   => 'bar',
						'baz'   => function($master)
						{
							return $master->baz - 111;
						},
					],
				],
			])->getMock();
		$behMock->expects($this->exactly(2))->method('findSlaveByPk')->will($this->returnValue($slaveMock));
		$behMock->expects($this->never())->method('createNewSlave');
		$behMock->expects($this->once())->method('deleteSlave')->with($slaveMock);

		//for update and
		// delete
		$behMock->sync();
		verify('attributes synced', $slaveMock->baz)->equals(909);
		verify('attributes synced', $slaveMock->title)->equals($attrs['name']);
		verify('attributes synced', $slaveMock->foo)->equals($attrs['bar']);

		$behMock->syncDelete();
	}

	public function testSmokeDbToDb()
	{
		$master = $this->masterModel;
		$slave = $this->slaveModel;
		$config = [
			'class'         => ArSyncBehavior::className(),
			'slaveModel'    => DbSlave::className(),
			'saveScenarios' => ['default'],
			'slaveScenario' => 'sync',
			'fieldMap'      => [
				'id'    => 'id',
				'title' => 'name',
				'foo'   => 'foo',
				'bar'   => 'bar',
				'baz'   => function($master)
				{
					return $master->baz * 2;
				},
			],
		];
		$config['errorSaveCallback'] = function($slave)
		{
			Debug::debug($slave->errors);
			return;
		};


		$this->specify('testAutoSync', function() use ($master, $slave, $config)
		{
			$master->attachBehavior('ArSyncBehavior', $config);
			verify_not($slave::findOne(15), 'slave record not exxists');
			$master->setAttributes(['id' => 15, 'name' => 'lala', 'foo' => 'bar', 'baz' => 10], false);
			$master->save();

			$slaveSync = $slave::findOne(15);
			verify_that($slaveSync, 'slave model appear');
			/**@var Verify* */
			verify('fieldMap success', $slaveSync->title)->equals('lala');
			verify('default values success', $slaveSync->bar)->equals('masterdefault');
			verify('closured values success', $slaveSync->baz)->equals(20);

		});

		$this->specify('test update record', function() use ($master, $slave, $config)
		{
			$model = $master::findOne(15);

			$model->attachBehavior('ArSyncBehavior', $config);
			$model->name = 'UpdatedName';
			$model->foo = 'newfoo';
			verify_that($model->save());

			$slaveSync = $slave::findOne(15);

			verify('slave record updated', $slaveSync->title)->equals('UpdatedName');

			$model->delete();
			verify_not($slave::findOne(15), 'after delete master, slave removed');
		});

		$this->specify('testManualSync', function() use ($master, $slave, $config)
		{
			verify('slave data empty', $slave::find()->count())->equals(0);
			$master->detachBehavior('ArSyncBehavior');
			verify_not($master->hasMethod('syncAll'), 'behavior not attached');
			//fill some data
			/**@var DbMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				$model->setAttributes(['name' => 'foo' . $i, 'foo' => 'bar' . $i, 'baz' => 10 + $i]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}

			verify('slave data empty yet, because behavior not attached', $slave::find()->count())->equals(0);
			$master->attachBehavior('ArSyncBehavior', $config);
			$master->syncAll();
			verify('data synced', $slave::find()->where([$slave->primaryKey()[0] => $ids])->count())->equals(5);
			//save not valid data
			$model = new $master;
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->setAttributes(['name' => 'baddata'], false);
			verify_not($model->save());

			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave not changed', $slave::find()->count())->equals(5);
			$models = $master::findAll($ids);
			foreach ($models as $model)
			{
				if ($model->baz == 10)
				{
					verify_that($model->delete() !== false);
				}
				else
				{
					$model->name = str_replace('foo', 'boo', $model->name);
					$model->updateAttributes(['name']);
				}
			}
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave count changed', $slave::find()->count())->equals(4);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				verify('slave title changed', $updSlave->title)->contains('boo');
			}

			$master->clearSlave();
			verify('slave must be empty', $slave::find()->count())->equals(0);

		});

		$this->specify('two-way binding test', function() use ($master, $slave, $config)
		{
			$this->clearModel($master);
			$this->clearModel($slave);
			$slaveConfig = [
				'class'           => ArSyncBehavior::className(),
				'slaveModel'      => DbMaster::className(),
				'saveScenarios'   => ['default'],
				'deleteScenarios' => [],
				'slaveScenario'   => 'sync',
				'fieldMap'        => [
					'foo' => 'foo',
					'bar' => 'bar',
				],
			];
			Event::on(DbMaster::className(), \yii\db\ActiveRecord::EVENT_INIT, function($event) use ($config)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $config);
			});
			Event::on(DbSlave::className(), \yii\db\ActiveRecord::EVENT_INIT, function($event) use ($slaveConfig)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $slaveConfig);
			});

			/**@var DbMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				verify_that($model->getBehavior('ArSyncBehavior'));
				$model->setAttributes([
					'name' => 'foo' . $i,
					'foo'  => 'foo' . $i,
					'bar'  => 'bar' . $i,
					'baz'  => 10 +
						$i,
				]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}
			verify('slave count changed', $slave::find()->count())->equals(5);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				$updSlave->foo = 'new_' . $updSlave->foo;
				$updSlave->bar = 'new_' . $updSlave->bar;
				$updSlave->save();
			}
			$masters = $master::find()->where(['in', $master->primaryKey(), $ids])->all();
			foreach ($masters as $newMaster)
			{
				verify('records was updated', $newMaster->foo)->startsWith('new_');
				verify('records was updated', $newMaster->bar)->startsWith('new_');
			}
		});

		$this->specify('test error callback', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$errors = [];
			$config['errorSaveCallback'] = function($slave) use (&$errors)
			{
				array_push($errors, $slave->errors);
				throw new InvalidValueException('fail save');
			};
			$master->attachBehavior('ArSyncBehavior', $config);
			verify('slave data empty', $slave::find()->count())->equals(0);
			verify('errors empty', count($errors))->equals(0);
			//save not valid data with skip rules
			$master->setAttributes(['name' => 'baddata', 'foo' => time()], false);
			$this->expectException(InvalidValueException::class);
			verify_that($master->save(false));
			verify('slave not changed', $slave::find()->count())->equals(0);
			verify('errors not empty', count($errors))->greaterThan(0);
		});


	}

	public function testSmokeDbToRedis()
	{
		//$this->markTestSkipped();
		$master = $this->masterModel;
		$slave = $this->slaveRedisModel;
		$config = [
			'class'         => ArSyncBehavior::className(),
			'slaveModel'    => RedisSlave::className(),
			'slaveScenario' => 'sync',
			'fieldMap'      => [
				'id'    => 'id',
				'title' => 'name',
				'foo'   => 'foo',
				'bar'   => 'bar',
				'baz'   => function($master)
				{
					return $master->baz * 2;
				},
			],
		];

		$this->specify('testAutoSync', function() use ($master, $slave, $config)
		{
			$master->attachBehavior('ArSyncBehavior', $config);
			verify_not($slave::findOne(15), 'slave record not exxists');
			$master->setAttributes(['id' => 15, 'name' => 'lala', 'foo' => 'bar', 'baz' => 10], false);
			$master->save();

			$slaveSync = $slave::findOne(15);
			verify_that($slaveSync, 'slave model appear');
			/**@var Verify* */
			verify('fieldMap success', $slaveSync->title)->equals('lala');
			verify('default values success', $slaveSync->bar)->equals('masterdefault');
			verify('closured values success', $slaveSync->baz)->equals(20);
		});

		$this->specify('test update record', function() use ($master, $slave, $config)
		{
			$model = $master::findOne(15);
			verify_that($model);
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->name = 'UpdatedName';
			verify_that($model->update());
			$slaveSync = $slave::findOne(15);
			verify('slave record updated', $slaveSync->title)->equals('UpdatedName');

			$model->delete();
			verify_not($slave::findOne(15), 'after delete master, slave removed');
		});
		$this->specify('testManualSync', function() use ($master, $slave, $config)
		{
			verify('slave data empty', $slave::find()->count())->equals(0);
			$master->detachBehavior('ArSyncBehavior');
			verify_not($master->hasMethod('syncAll'), 'behavior not attached');
			//fill some data
			/**@var DbMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				$model->setAttributes(['name' => 'foo' . $i, 'foo' => 'bar' . $i, 'baz' => 10 + $i]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}

			verify('slave data empty yet, because behavior not attached', $slave::find()->count())->equals(0);
			$master->attachBehavior('ArSyncBehavior', $config);
			$master->syncAll();
			verify('data synced', $slave::find()->where([$slave->primaryKey()[0] => $ids])->count())->equals(5);
			//save not valid data
			$model = new $master;
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->setAttributes(['name' => 'baddata'], false);
			verify_not($model->save());
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave not changed', $slave::find()->count())->equals(5);
			$models = $master::findAll($ids);
			foreach ($models as $model)
			{
				if ($model->baz == 10)
				{
					verify_that($model->delete() !== false);
				}
				else
				{
					$model->name = str_replace('foo', 'boo', $model->name);
					$model->updateAttributes(['name']);
				}
			}
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave count changed', $slave::find()->count())->equals(4);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				verify('slave title changed', $updSlave->title)->contains('boo');
			}

			$master->clearSlave();
			verify('slave must be empty', $slave::find()->count())->equals(0);
		});
		$this->specify('two-way binding test', function() use ($master, $slave, $config)
		{
			$this->clearModel($master);
			$this->clearModel($slave);
			$slaveConfig = [
				'class'           => ArSyncBehavior::className(),
				'slaveModel'      => DbMaster::className(),
				'saveScenarios'   => ['default'],
				'deleteScenarios' => [],
				'slaveScenario'   => 'sync',
				'fieldMap'        => [
					'foo' => 'foo',
					'bar' => 'bar',
				],
			];
			Event::on(DbMaster::className(), \yii\db\ActiveRecord::EVENT_INIT, function($event) use ($config)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $config);
			});
			Event::on(RedisSlave::className(), ActiveRecord::EVENT_INIT, function($event) use ($slaveConfig)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $slaveConfig);
			});

			/**@var DbMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				verify_that($model->getBehavior('ArSyncBehavior'));

				$model->setAttributes([
					'name' => 'foo' . $i,
					'foo'  => 'foo' . $i,
					'bar'  => 'bar' . $i,
					'baz'  => 10 +
						$i,
				]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}
			verify('slave count changed', $slave::find()->count())->equals(5);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				$updSlave->foo = 'new_' . $updSlave->foo;
				$updSlave->bar = 'new_' . $updSlave->bar;
				$updSlave->save();
			}
			$masters = $master::find()->where(['in', $master->primaryKey(), $ids])->all();
			foreach ($masters as $newMaster)
			{
				verify('records was updated', $newMaster->foo)->startsWith('new_');
				verify('records was updated', $newMaster->bar)->startsWith('new_');
			}
		});
		$this->specify('test error callback', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$errors = [];
			$config['errorSaveCallback'] = function($slave) use (&$errors)
			{
				array_push($errors, $slave->errors);
				throw new InvalidValueException('fail save');
			};
			$master->attachBehavior('ArSyncBehavior', $config);
			verify('slave data empty', $slave::find()->count())->equals(0);
			verify('errors empty', count($errors))->equals(0);
			//save not valid data with skip rules
			$master->setAttributes(['name' => 'baddata', 'foo' => time()], false);
			$this->expectException(InvalidValueException::class);
			verify_that($master->save(false));
			verify('slave not changed', $slave::find()->count())->equals(0);
			verify('errors not empty', count($errors))->greaterThan(0);

		});


	}

	public function testSmokeRedisToRedis()
	{
		//$this->markTestSkipped();

		$master = $this->masterRedisModel;
		$slave = $this->slaveRedisModel;
		$config = [
			'class'         => ArSyncBehavior::className(),
			'slaveModel'    => RedisSlave::className(),
			'slaveScenario' => 'sync',
			'fieldMap'      => [
				'id'    => 'id',
				'title' => 'name',
				'foo'   => 'foo',
				'bar'   => 'bar',
				'baz'   => function($master)
				{
					return $master->baz * 2;
				},
			],
		];

		$this->specify('testAutoSync', function() use ($master, $slave, $config)
		{
			$master->attachBehavior('ArSyncBehavior', $config);
			verify_not($slave::findOne(15), 'slave record not exxists');
			$master->setAttributes(['id' => 15, 'name' => 'lala', 'foo' => 'bar', 'baz' => 10], false);
			$master->save();

			$slaveSync = $slave::findOne(15);
			verify_that($slaveSync, 'slave model appear');
			/**@var Verify* */
			verify('fieldMap success', $slaveSync->title)->equals('lala');
			verify('default values success', $slaveSync->bar)->equals('masterdefault');
			verify('closured values success', $slaveSync->baz)->equals(20);
		});

		$this->specify('test update record', function() use ($master, $slave, $config)
		{
			$model = $master::findOne(15);
			verify_that($model);
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->name = 'UpdatedName';
			verify_that($model->update());
			$slaveSync = $slave::findOne(15);
			verify('slave record updated', $slaveSync->title)->equals('UpdatedName');

			$model->delete();
			verify_not($slave::findOne(15), 'after delete master, slave removed');
		});

		$this->specify('testManualSync', function() use ($master, $slave, $config)
		{
			verify('slave data empty', $slave::find()->count())->equals(0);
			$master->detachBehavior('ArSyncBehavior');
			verify_not($master->hasMethod('syncAll'), 'behavior not attached');
			//fill some data
			/**@var DbMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				$model->setAttributes(['name' => 'foo' . $i, 'foo' => 'bar' . $i, 'baz' => 10 + $i]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}

			verify('slave data empty yet, because behavior not attached', $slave::find()->count())->equals(0);
			$master->attachBehavior('ArSyncBehavior', $config);
			$master->syncAll();
			verify('data synced', $slave::find()->where([$slave->primaryKey()[0] => $ids])->count())->equals(5);
			//save not valid data
			$model = new $master;
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->setAttributes(['name' => 'baddata'], false);
			verify_not($model->save());
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave not changed', $slave::find()->count())->equals(5);
			$models = $master::findAll($ids);
			foreach ($models as $model)
			{
				if ($model->baz == 10)
				{
					verify_that($model->delete() !== false);
				}
				else
				{
					$model->name = str_replace('foo', 'boo', $model->name);
					$model->updateAttributes(['name']);
				}
			}
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave count changed', $slave::find()->count())->equals(4);
			/**@var RedisSlave $slave * */
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				verify('slave title changed', $updSlave->title)->contains('boo');
			}

			$master->clearSlave();
			verify('slave must be empty', $slave::find()->count())->equals(0);
		});
		$this->specify('two-way binding test', function() use ($master, $slave, $config)
		{
			$this->clearModel($master);
			$this->clearModel($slave);
			$slaveConfig = [
				'class'           => ArSyncBehavior::className(),
				'slaveModel'      => RedisMaster::className(),
				'saveScenarios'   => ['default'],
				'deleteScenarios' => [],
				'slaveScenario'   => 'sync',
				'fieldMap'        => [
					'foo' => 'foo',
					'bar' => 'bar',
				],
			];

			Event::on(RedisMaster::className(), ActiveRecord::EVENT_INIT, function($event) use ($config)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $config);
			});
			Event::on(RedisSlave::className(), ActiveRecord::EVENT_INIT, function($event) use ($slaveConfig)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $slaveConfig);
			});

			/**@var RedisMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				verify_that($model->getBehavior('ArSyncBehavior'));
				$model->setAttributes([
					'name' => 'foo' . $i,
					'foo'  => 'foo' . $i,
					'bar'  => 'bar' . $i,
					'baz'  => 10 +
						$i,
				]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}
			verify('slave count changed', $slave::find()->count())->equals(5);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{

				$updSlave->foo = 'new_' . $updSlave->foo;
				$updSlave->bar = 'new_' . $updSlave->bar;
				$updSlave->save();
			}
			$masters = $master::find()->where(['in', $master->primaryKey(), $ids])->all();
			foreach ($masters as $newMaster)
			{

				verify('records was updated', $newMaster->foo)->startsWith('new_');
				verify('records was updated', $newMaster->bar)->startsWith('new_');
			}
		});

		$this->specify('test error callback', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$errors = [];
			$config['errorSaveCallback'] = function($slave) use (&$errors)
			{
				array_push($errors, $slave->errors);
				throw new InvalidValueException('fail save');
			};
			$master->attachBehavior('ArSyncBehavior', $config);
			verify('slave data empty', $slave::find()->count())->equals(0);
			verify('errors empty', count($errors))->equals(0);
			//save not valid data with skip rules
			$master->setAttributes(['name' => 'baddata', 'foo' => time()], false);
			$this->expectException(InvalidValueException::class);
			verify_that($master->save(false));
			verify('slave not changed', $slave::find()->count())->equals(0);
			verify('errors not empty', count($errors))->greaterThan(0);

		});

	}

	public function testSmokeRedisToDb()
	{
		//$this->markTestSkipped();

		$master = $this->masterRedisModel;
		$slave = $this->slaveModel;
		$config = [
			'class'         => ArSyncBehavior::className(),
			'slaveModel'    => DbSlave::className(),
			'slaveScenario' => 'sync',
			'fieldMap'      => [
				'id'    => 'id',
				'title' => 'name',
				'foo'   => 'foo',
				'bar'   => 'bar',
				'baz'   => function($master)
				{
					return $master->baz * 2;
				},
			],
		];

		$this->specify('testAutoSync', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$this->clearModel($master);
			$master->attachBehavior('ArSyncBehavior', $config);
			$master->setIsNewRecord(true);
			verify_not($slave::findOne(15), 'slave record not exxists');
			$master->setAttributes(['id' => 15, 'name' => 'lala', 'foo' => 'bar', 'baz' => 10], false);
			verify_that($master->save());

			$slaveSync = $slave::findOne(15);
			verify_that($slaveSync, 'slave model appear ');
			/**@var Verify* */
			verify('fieldMap success', $slaveSync->title)->equals('lala');
			verify('default values success', $slaveSync->bar)->equals('masterdefault');
			verify('closured values success', $slaveSync->baz)->equals(20);

		});
		$this->specify('test update record', function() use ($master, $slave, $config)
		{
			$model = $master::findOne(15);
			verify_that($model);
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->name = 'UpdatedName';
			verify_that($model->update());
			$slaveSync = $slave::findOne(15);
			verify('slave record updated', $slaveSync->title)->equals('UpdatedName');

			$model->delete();
			verify_not($slave::findOne(15), 'after delete master, slave removed');
		});

		$this->specify('testManualSync', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$this->clearModel($master);
			verify('slave data empty', $slave::find()->count())->equals(0);
			$master->detachBehavior('ArSyncBehavior');
			verify_not($master->hasMethod('syncAll'), 'behavior not attached');
			//fill some data
			/**@var RedisMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				$model->setAttributes(['name' => 'foo' . $i, 'foo' => 'bar' . $i, 'baz' => 10 + $i]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}

			verify('slave data empty yet, because behavior not attached', $slave::find()->count())->equals(0);
			$master->attachBehavior('ArSyncBehavior', $config);
			$master->syncAll();
			verify('data synced', $slave::find()->where([$slave->primaryKey()[0] => $ids])->count())->equals(5);
			//save not valid data
			$model = new $master;
			$model->attachBehavior('ArSyncBehavior', $config);
			$model->setAttributes(['name' => 'baddata'], false);
			verify_not($model->save());
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll(true);
			verify('slave not changed', $slave::find()->count())->equals(5);

			$models = $master::findAll($ids);
			foreach ($models as $model)
			{
				if ($model->baz == 10)
				{
					verify_that($model->delete() !== false);
				}
				else
				{
					$model->name = str_replace('foo', 'boo', $model->name);
					$model->updateAttributes(['name']);
				}
			}
			verify('slave not changed', $slave::find()->count())->equals(5);
			$master->syncAll();
			verify('slave count changed', $slave::find()->count())->equals(4);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				verify('slave title changed', $updSlave->title)->contains('boo');
			}

			$master->clearSlave();
			verify('slave must be empty', $slave::find()->count())->equals(0);
		});
		$this->specify('test two-way binding', function() use ($master, $slave, $config)
		{
			$this->clearModel($master);
			$this->clearModel($slave);
			$slaveConfig = [
				'class'           => ArSyncBehavior::className(),
				'slaveModel'      => RedisMaster::className(),
				'saveScenarios'   => ['default'],
				'deleteScenarios' => [],
				'slaveScenario'   => 'sync',
				'fieldMap'        => [
					'foo' => 'foo',
					'bar' => 'bar',
				],
			];
			Event::on(RedisMaster::className(), ActiveRecord::EVENT_INIT, function($event) use ($config)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $config);
			});
			Event::on(DbSlave::className(), \yii\db\ActiveRecord::EVENT_INIT, function($event) use ($slaveConfig)
			{
				$event->sender->attachBehavior('ArSyncBehavior', $slaveConfig);
			});

			/**@var RedisMaster $model */
			$ids = [];
			for ($i = 0; $i < 5; $i++)
			{
				$model = new $master;
				verify_that($model->getBehavior('ArSyncBehavior'));
				$model->setAttributes([
					'name' => 'foo' . $i,
					'foo'  => 'foo' . $i,
					'bar'  => 'bar' . $i,
					'baz'  => 10 +
						$i,
				]);
				verify_that($model->save());
				$ids[] = $model->getPrimaryKey();
			}
			verify('slave count changed',
				$slave::find()->where(['in', $slave->primaryKey(), $ids])->count())->equals(5);
			$slaves = $slave::find()->where(['in', $slave->primaryKey(), $ids])->all();
			foreach ($slaves as $updSlave)
			{
				verify_that($updSlave->getBehavior('ArSyncBehavior'));
				$updSlave->foo = 'new_' . $updSlave->foo;
				$updSlave->bar = 'new_' . $updSlave->bar;
				$updSlave->save();
			}
			$masters = $master::find()->where(['in', $master->primaryKey(), $ids])->all();
			foreach ($masters as $newMaster)
			{
				verify('records was updated', $newMaster->foo)->startsWith('new_');
				verify('records was updated', $newMaster->bar)->startsWith('new_');
			}
		});

		$this->specify('test error callback', function() use ($master, $slave, $config)
		{
			$this->clearModel($slave);
			$errors = [];
			$config['errorSaveCallback'] = function($slave) use (&$errors)
			{
				array_push($errors, $slave->errors);
				throw new InvalidValueException('fail save');
			};
			$master->setIsNewRecord(true);
			$master->attachBehavior('ArSyncBehavior', $config);
			verify('slave data empty', $slave::find()->count())->equals(0);
			verify('errors empty', count($errors))->equals(0);
			//save not valid data with skip rules
			$master->setAttributes(['name' => 'baddata', 'foo' => time()], false);
			$this->expectException(InvalidValueException::class);
			verify_that($master->save(false));
			verify('slave not changed for' . $master->id, $slave::find()->count())->equals(0);
			verify('errors not empty', count($errors))->greaterThan(0);
		});


	}

}