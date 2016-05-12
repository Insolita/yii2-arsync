<?php
/**
 * Created by insolita [10.05.16 23:18]
 */

namespace insolita\arsync;


use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\db\ActiveRecordInterface;
use yii\db\Connection;
use yii\helpers\ArrayHelper;

/**
 * This behavior for automatic or manual sync data between two models, without declaration relation
 * This behavior must be attached on master model
 * Support two-way binding (You can attach this on both model with correct fieldMap. ATTENTION! Behavior name must be
 * exactly 'ArSyncBehavior' in both models!)
 * This behavior provide syncAll() method for manual use (clear all data from slave model and refill with data
 * from master model accordingly fieldMap rules)
 *
 * Main purposes - for sync rarely modified data from more reliable database storage to redis storage for
 * frequently access; Support actual data state in some development cases;
 *
 * Suggest - @see http://www.yiiframework.com/doc-2.0/yii-db-activerecord.html#transactions%28%29-detail
 **/
class ArSyncBehavior extends Behavior
{
	/**
	 * @var string $slaveModel modelClass which will be sync with owner
	 **/
	public $slaveModel;

	/**
	 * @var array $fieldMap - map between master and slave models; must be set as flat array
	 *
	 *    if all models has equal field names and not need data modification
	 * @example
	 *    'fieldMap'=>['id','name','somevar']
	 *    ----------------------------------------
	 *    if fields not with equal name, configure map as hash, where keys - fieldNames of Slave model
	 *    and values -> fieldNames of Master model
	 * @example
	 *    'fieldMap'=>[
	 *        'name' => 'orgname',
	 *        'created' => 'time'
	 *        'foo'    =>  'foo'
	 *    ]
	 *   -----------------------------------------
	 *   if data need modification - use closure with argument - master model
	 * @example
	 *    'fieldMap'=>[
	 *        'foo' =>  'foo',
	 *        'name' => function($master){ return strtolower($master->orgname); },
	 *        'delta' => function($master){ return $master->updated - $master->started; }
	 *        'bar'    =>  'baz'
	 *    ]
	 *
	 **/
	public $fieldMap = [];

	/**
	 * scenario for operations with $slaveModel
	 *
	 * @var string $slaveScenario
	 */
	public $slaveScenario;
	/**
	 * @var array $saveScenarios - list scenarios, when record must be sync (set empty for only manual
	 * synchronization)
	 **/
	public $saveScenarios = ['default'];
	/**
	 * @var array $deleteScenarios - list scenarios, when record must delete record from slave model (set empty for only
	 * manual synchronization)
	 **/
	public $deleteScenarios = ['default'];

	/**
	 * callback triggered if $slave model can`t saved with $slaveModel argument
	 *
	 * @example
	 * 'errorSaveCallback'=>function($slave){
	 *       Yii::error(VarDumper::export($slave->errors));
	 *       throw new InvalidConfigException('fail save ');
	 * }
	 * @var callable|\Closure $errorSaveCallback
	 **/
	public $errorSaveCallback;

	/**
	 * callback triggered if $slave model can`t deleted with slave model as argument? or null if slave not found by pk
	 *
	 * @see $errorSaveCallback comment
	 * @var callable|\Closure $errorDeleteCallback
	 **/
	public $errorDeleteCallback;

	/**
	 * Prepare fieldMap
	 *
	 * @throws InvalidConfigException
	 */
	public function init()
	{
		parent::init();
		$this->fieldMap = $this->prepareFieldMap($this->fieldMap);
	}

	/**
	 * @param $fieldMap
	 *
	 * @return mixed
	 * @throws InvalidConfigException
	 */
	protected function prepareFieldMap($fieldMap)
	{
		if (ArrayHelper::isIndexed($fieldMap))
		{
			return array_combine($fieldMap, $fieldMap);
		}
		if (!ArrayHelper::isAssociative($fieldMap))
		{
			throw new InvalidConfigException('Incorrect configuration for fieldMap property');
		}
		return $fieldMap;
	}

	/**
	 * @return array
	 */
	public function events()
	{
		return [
			ActiveRecord::EVENT_AFTER_INSERT => 'sync',
			ActiveRecord::EVENT_AFTER_UPDATE => 'sync',
			ActiveRecord::EVENT_AFTER_DELETE => 'syncDelete',
		];
	}

	/**
	 * Sync current record
	 */
	public function sync()
	{
		if (!$this->checkIsAutoSyncEnabled())
		{
			return;
		}
		$master = $this->getOwner();
		$this->syncRecord($master);
	}

	/**
	 * @return bool
	 **/
	protected function checkIsAutoSyncEnabled()
	{
		return in_array($this->getOwner()->scenario, $this->saveScenarios);
	}

	/**
	 * @return ActiveRecord|ActiveRecordInterface
	 **/
	public function getOwner()
	{

		return $this->owner;
	}

	/**
	 * @param ActiveRecordInterface|Model $master
	 */
	protected function syncRecord(ActiveRecordInterface $master)
	{
		/**@var ActiveRecordInterface|Model $slave * */
		$slave = null;
		if (!$master->getIsNewRecord())
		{
			$slave = $this->findSlaveByPk($master->getPrimaryKey());
		}
		if ($master->getIsNewRecord() or !$slave)
		{
			//if related slave not exists - create new slave too
			$slave = $this->createNewSlave();
		}
		$hasArsync = $slave->getBehavior('ArSyncBehavior');
		if ($hasArsync)
		{
			$slave->detachBehavior('ArSyncBehavior'); //prevent conflict if sync with two-way binding
		}
		if ($this->slaveScenario)
		{
			$slave->setScenario($this->slaveScenario);
		}
		$slave = $this->populateSlave($master, $slave, $this->fieldMap);
		$this->saveSlave($slave);
		if ($hasArsync)
		{
			$slave->attachBehavior('ArSyncBehavior', $hasArsync);
		}
	}

	/**
	 * @param int|string $pk
	 *
	 * @return ActiveRecordInterface|Model|null
	 **/
	protected function findSlaveByPk($pk)
	{
		return call_user_func([$this->slaveModel, 'findOne'], $pk);
	}

	/**
	 * @return ActiveRecordInterface|Model
	 */
	protected function createNewSlave()
	{
		return new $this->slaveModel;
	}

	/**
	 * @param ActiveRecordInterface $master
	 * @param ActiveRecordInterface $slave
	 * @param                       $fieldMap
	 *
	 * @return ActiveRecordInterface|Model $slave
	 */
	protected function populateSlave(ActiveRecordInterface $master, ActiveRecordInterface $slave, $fieldMap)
	{
		foreach ($fieldMap as $slaveKey => $masterKey)
		{
			if (is_callable($masterKey))
			{
				$slave->$slaveKey = call_user_func($masterKey, $master);
			}
			else
			{
				$slave->$slaveKey = $master->$masterKey;
			}
		}
		return $slave;
	}

	/**
	 * @param ActiveRecordInterface $slave
	 */
	protected function saveSlave(ActiveRecordInterface $slave)
	{
		if (!$slave->save())
		{
			if ($this->errorSaveCallback !== null && is_callable([$this, 'errorSaveCallback']))
			{
				call_user_func($this->errorSaveCallback, $slave);
			}
		}
	}

	/**
	 * Remove slave record
	 */
	public function syncDelete()
	{
		if (!$this->checkIsAutoDeleteEnabled())
		{
			return;
		}
		$masterPk = $this->getOwner()->getPrimaryKey();
		$slave = $this->findSlaveByPk($masterPk);
		if ($slave)
		{
			$hasArsync = $slave->getBehavior('ArSyncBehavior');
			if ($hasArsync)
			{
				$slave->detachBehavior('ArSyncBehavior'); //prevent conflict if sync with two-way binding
			}
			if ($this->slaveScenario and $slave->hasMethod('setScenario'))
			{
				$slave->setScenario($this->slaveScenario);
			}
			$this->deleteSlave($slave);
			if ($hasArsync)
			{
				$slave->attachBehavior('ArSyncBehavior', $hasArsync);
			}
		}
		elseif ($this->errorDeleteCallback !== null && is_callable($this->errorDeleteCallback))
		{
			\Yii::error('slave record not found by PK ' . $masterPk, get_called_class());
			call_user_func($this->errorDeleteCallback, $slave);
		}
	}

	/**
	 * @return bool
	 **/
	protected function checkIsAutoDeleteEnabled()
	{
		return in_array($this->getOwner()->scenario, $this->deleteScenarios);
	}

	/**
	 * Delete slave record
	 *
	 * @param ActiveRecordInterface $slave
	 */
	protected function deleteSlave(ActiveRecordInterface $slave)
	{
		if ($slave->delete() == false)
		{
			if (is_callable($this->errorDeleteCallback))
			{
				call_user_func($this->errorDeleteCallback, $slave);
			}
		}
	}

	/**
	 * It`s manual method for fill slaveModel with data from Master model accordingly @see $fieldMap method
	 * It get each master record, check exists of slave record with same primary key and update existed, or create
	 * new slave
	 * It can be hard for database, if master model has many records
	 * Suggest use it with maintenance mode or in development
	 *
	 * @param int  $batchRows  - number of rows in batch mode (only if batch find supported by model storage)
	 * @param bool $clearSlave - if true - all slave records will deleted and table will be truncated if it`s possible
	 */
	public function syncAll($clearSlave = false, $batchRows = 50)
	{
		if ($clearSlave)
		{
			$this->clearSlave();
		}

		/**@var ActiveRecordInterface|Model $master * */
		$master = $this->getOwner();
		$finder = $master::find();

		$masterIds = [];
		if ($finder->hasMethod('batch'))
		{
			foreach ($finder->batch($batchRows) as $items)
			{
				foreach ($items as $item)
				{
					$masterIds[] = $item->getPrimaryKey();
					$this->syncRecord($item);
				}
			}
		}
		else
		{
			foreach ($finder->all() as $item)
			{
				$masterIds[] = $item->getPrimaryKey();
				$this->syncRecord($item);
			}
		}

		if ($clearSlave == false && count($masterIds))
		{
			/**@var ActiveRecordInterface|Model $slave * */
			$slave = new $this->slaveModel;
			//Delete unexpected Slaves, not presented in master
			$slave::deleteAll(['not in',$slave->primaryKey()[0], $masterIds]);
		}
	}

	/**
	 * clear all data from slave and truncate table if it possible
	 **/
	public function clearSlave()
	{
		/**@var ActiveRecordInterface|Model $slave */
		$slave = new $this->slaveModel;
		$slave::deleteAll();
		$db = $slave::getDb();
		if ($db instanceof Connection and $slave->hasMethod('tableName', false))
		{
			$db->createCommand()->truncateTable($slave::tableName());
			if ($db->getDriverName() == 'pgsql')
			{
				$db->createCommand()->resetSequence($slave::tableName(), 1);
			}
		}
	}
}