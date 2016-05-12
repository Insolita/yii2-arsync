<?php
/**
 * Created by solly [11.05.16 23:58]
 */

namespace tests\codeception\common\unit\fixtures;


use yii\redis\ActiveRecord;
/**
 * @property int $id
 * @property string $title
 * @property string $foo
 * @property string $bar
 * @property int $baz
**/
class RedisSlave extends ActiveRecord
{
	public function attributes()
	{
		return ['id','title','foo','bar','baz'];
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		return [
			[['title', 'foo', 'baz'], 'required'],
			[['bar'], 'default','value'=>'slavedefault'],
			[['title', 'foo', 'bar'], 'string', 'max' => 15],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function scenarios()
	{
		return [
			'default' => ['id','title','foo','bar','baz'],
			'sync' => ['id','title','foo','bar','baz'],
		];
	}
}