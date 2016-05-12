<?php
/**
 * Created by solly [11.05.16 23:58]
 */

namespace tests\codeception\common\unit\fixtures;


use yii\redis\ActiveRecord;
/**
 * @property int $id
 * @property string $name
 * @property string $foo
 * @property string $bar
 * @property int $baz
**/
class RedisMaster extends ActiveRecord
{
	public function attributes()
	{
		return ['id','name','foo','bar','baz'];
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		return [
			[['name', 'foo'], 'required'],
			[['baz'],'default','value'=>'emptybaz'],
			[['bar'], 'default','value'=>'masterdefault'],
			[['name', 'foo', 'bar'], 'string', 'max' => 15],
			['id', 'safe','on'=>'sync']
		];
	}

	/**
	 * @inheritdoc
	 */
	public function scenarios()
	{
		return [
			'default' => ['id','name','foo','bar','baz'],
			'sync' => ['id','name','foo','bar','baz'],
		];
	}
}