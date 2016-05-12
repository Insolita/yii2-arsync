<?php
/**
 * Created by solly [11.05.16 23:58]
 */

namespace tests\codeception\common\unit\fixtures;


use yii\db\ActiveRecord;
/**
 * @property int $id
 * @property string $title
 * @property string $foo
 * @property string $bar
 * @property int $baz
**/
class DbSlave extends ActiveRecord
{
    public static function tableName(){
	    return '{{%slave}}';
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
			[['id'],'safe','on'=>'sync']
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