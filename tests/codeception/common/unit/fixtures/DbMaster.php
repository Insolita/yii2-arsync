<?php
/**
 * Created by solly [11.05.16 23:58]
 */

namespace tests\codeception\common\unit\fixtures;


use yii\db\ActiveRecord;
/**
 * @property int $id
 * @property string $name
 * @property string $foo
 * @property string $bar
 * @property int $baz
 * @property datetime $created
**/
class DbMaster extends ActiveRecord
{
    public static function tableName(){
	    return '{{%master}}';
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
		];
	}

	/**
	 * @inheritdoc
	 */
	public function scenarios()
	{
		return [
			'default' => ['name','foo','bar','baz'],
			'sync' => ['name','foo','bar','baz'],
		];
	}
}