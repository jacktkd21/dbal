<?php
/*
 *  $Id: Hydrate.php 1255 2007-04-16 14:43:12Z pookey $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.phpdoctrine.com>.
 */

/**
 * Doctrine_Hydrate is a base class for Doctrine_RawSql and Doctrine_Query.
 * Its purpose is to populate object graphs.
 *
 *
 * @package     Doctrine
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @category    Object Relational Mapping
 * @link        www.phpdoctrine.com
 * @since       1.0
 * @version     $Revision: 1255 $
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 */
class Doctrine_Hydrate2
{
    /**
     * QUERY TYPE CONSTANTS
     */

    /**
     * constant for SELECT queries
     */
    const SELECT = 0;
    /**
     * constant for DELETE queries
     */
    const DELETE = 1;
    /**
     * constant for UPDATE queries
     */
    const UPDATE = 2;
    /**
     * constant for INSERT queries
     */
    const INSERT = 3;
    /**
     * constant for CREATE queries
     */
    const CREATE = 4;

    /**
     * @var array $params                       query input parameters
     */
    protected $params      = array();
    /**
     * @var Doctrine_Connection $conn           Doctrine_Connection object
     */
    protected $conn;
    /**
     * @var Doctrine_View $view                 Doctrine_View object
     */
    protected $view;
    /**
     * @var array $_aliasMap                    two dimensional array containing the map for query aliases
     *      Main keys are component aliases
     *
     *          table               table object associated with given alias
     *
     *          relation            the relation object owned by the parent
     *
     *          parent              the alias of the parent
     */
    protected $_aliasMap        = array();

    /**
     * @var array $tableAliases
     */
    protected $tableAliases = array();
    /**
     *
     */
    protected $pendingAggregates = array();
    /** 
     *
     */
    protected $subqueryAggregates = array();
    /**
     * @var array $aggregateMap             an array containing all aggregate aliases, keys as dql aliases
     *                                      and values as sql aliases
     */
    protected $aggregateMap      = array();
    /**
     * @var Doctrine_Hydrate_Alias $aliasHandler
     */
    protected $aliasHandler;
    /**
     * @var array $parts            SQL query string parts
     */
    protected $parts = array(
        'select'    => array(),
        'from'      => array(),
        'set'       => array(),
        'join'      => array(),
        'where'     => array(),
        'groupby'   => array(),
        'having'    => array(),
        'orderby'   => array(),
        'limit'     => false,
        'offset'    => false,
        );
    /**
     * @var integer $type                   the query type
     *
     * @see Doctrine_Query::* constants
     */
    protected $type            = self::SELECT;
    /**
     * constructor
     *
     * @param Doctrine_Connection|null $connection
     */
    public function __construct($connection = null)
    {
        if ( ! ($connection instanceof Doctrine_Connection)) {
            $connection = Doctrine_Manager::getInstance()->getCurrentConnection();
        }
        $this->conn = $connection;
        $this->aliasHandler = new Doctrine_Hydrate_Alias();
    }
    /**
     * getTableAliases
     *
     * @return array
     */
    public function getTableAliases()
    {
        return $this->tableAliases;
    }
    public function setTableAliases(array $aliases)
    {
        $this->tableAliases = $aliases;
    }
    /**
     * copyAliases
     *
     * @return void
     */
    public function copyAliases(Doctrine_Hydrate $query)
    {
        $this->tableAliases = $query->getTableAliases();
        $this->aliasHandler = $query->aliasHandler;

        return $this;
    }
    /**
     * createSubquery
     *
     * @return Doctrine_Hydrate
     */
    public function createSubquery()
    {
        $class = get_class($this);
        $obj   = new $class();

        // copy the aliases to the subquery
        $obj->copyAliases($this);

        // this prevents the 'id' being selected, re ticket #307
        $obj->isSubquery(true);

        return $obj;
    }
    /**
     * limitSubqueryUsed
     *
     * @return boolean
     */
    public function isLimitSubqueryUsed()
    {
        return false;
    }
    public function getQueryPart($part) 
    {
        if ( ! isset($this->parts[$part])) {
            throw new Doctrine_Hydrate_Exception('Unknown query part ' . $part);
        }
        
        return $this->parts[$part];
    }
    /**
     * remove
     *
     * @param $name
     */
    public function remove($name)
    {
        if (isset($this->parts[$name])) {
            if ($name == "limit" || $name == "offset") {
                $this->parts[$name] = false;
            } else {
                $this->parts[$name] = array();
            }
        }
        return $this;
    }
    /**
     * clear
     * resets all the variables
     *
     * @return void
     */
    protected function clear()
    {
        $this->tables       = array();
        $this->parts = array(
                  "select"    => array(),
                  "from"      => array(),
                  "join"      => array(),
                  "where"     => array(),
                  "groupby"   => array(),
                  "having"    => array(),
                  "orderby"   => array(),
                  "limit"     => false,
                  "offset"    => false,
                );
        $this->inheritanceApplied = false;
        $this->tableAliases     = array();
        $this->aliasHandler->clear();
    }
    /**
     * getConnection
     *
     * @return Doctrine_Connection
     */
    public function getConnection()
    {
        return $this->conn;
    }
    /**
     * setView
     * sets a database view this query object uses
     * this method should only be called internally by doctrine
     *
     * @param Doctrine_View $view       database view
     * @return void
     */
    public function setView(Doctrine_View $view)
    {
        $this->view = $view;
    }
    /**
     * getView
     * returns the view associated with this query object (if any)
     *
     * @return Doctrine_View        the view associated with this query object
     */
    public function getView()
    {
        return $this->view;
    }
    /**
     * getParams
     *
     * @return array
     */
    public function getParams()
    {
        return $this->params;                           	
    }
    /**
     * setParams
     *
     * @param array $params
     */
    public function setParams(array $params = array()) {
        $this->params = $params;
    }
    /**
     * _fetch
     *
     *
     */
    public function _fetch($params = array(), $return = Doctrine::FETCH_RECORD)
    {
        $params = $this->conn->convertBooleans(array_merge($this->params, $params));

        if ( ! $this->view) {
            $query = $this->getQuery($params);
        } else {
            $query = $this->view->getSelectSql();
        }

        if ($this->isLimitSubqueryUsed() && 
            $this->conn->getDBH()->getAttribute(Doctrine::ATTR_DRIVER_NAME) !== 'mysql') {
            
            $params = array_merge($params, $params);
        }
        $stmt  = $this->conn->execute($query, $params);

        return $this->parseData($stmt);
    }
    
    public function setAliasMap($map) 
    {
        $this->_aliasMap = $map;
    }
    public function getAliasMap() 
    {
        return $this->_aliasMap;
    }
    /**
     * mapAggregateValues
     * map the aggregate values of given dataset row to a given record
     *
     * @param Doctrine_Record $record
     * @param array $row
     * @return Doctrine_Record
     */
    public function mapAggregateValues($record, array $row)
    {
        // aggregate values have numeric keys
        if (isset($row[0])) {
            // map each aggregate value
            foreach ($row as $index => $value) {
                $agg = false;

                if (isset($this->pendingAggregates[$alias][$index])) {
                    $agg = $this->pendingAggregates[$alias][$index][3];
                } elseif (isset($this->subqueryAggregates[$alias][$index])) {
                    $agg = $this->subqueryAggregates[$alias][$index];
                }
                $record->mapValue($agg, $value);
            }
        } 
        return $record;
    }
    /**
     * execute
     * executes the dql query and populates all collections
     *
     * @param string $params
     * @return Doctrine_Collection            the root collection
     */
    public function execute($params = array(), $return = Doctrine::FETCH_RECORD) 
    {
        $array = (array) $this->_fetch($params = array(), $return = Doctrine::FETCH_RECORD);

        if (empty($this->_aliasMap)) {
            throw new Doctrine_Hydrate_Exception("Couldn't execute query. Component alias map was empty.");
        }
        // initialize some variables used within the main loop
        $rootMap     = current($this->_aliasMap);
        $rootAlias   = key($this->_aliasMap);
        $coll        = new Doctrine_Collection2($rootMap['table']);
        $prev[$rootAlias] = $coll;

        $prevRow = array();
        /**
         * iterate over the fetched data
         * here $data is a two dimensional array
         */
        foreach ($array as $data) {
            /**
             * remove duplicated data rows and map data into objects
             */
            foreach ($data as $tableAlias => $row) {
                // skip empty rows (not mappable)
                if (empty($row)) {
                    continue;
                }
                // check for validity
                if ( ! isset($this->tableAliases[$tableAlias])) {
                    throw new Doctrine_Hydrate_Exception('Unknown table alias ' . $tableAlias);
                }
                $alias = $this->tableAliases[$tableAlias];
                $map   = $this->_aliasMap[$alias];

                // initialize previous row array if not set
                if ( ! isset($prevRow[$tableAlias])) {
                    $prevRow[$tableAlias] = array();
                }

                // don't map duplicate rows
                if ($prevRow[$tableAlias] !== $row) {
                    // set internal data
                    $map['table']->setData($row);

                    $identifiable = $this->isIdentifiable($row, $map['table']->getIdentifier());
                    
                    // only initialize record if the current data row is identifiable
                    if ($identifiable) {
                        // initialize a new record
                        $record = $map['table']->getRecord();
                    }


                    // map aggregate values (if any)
                    $this->mapAggregateValues($record, $row);


                    if ($alias == $rootAlias) {
                        // add record into root collection
    
                        if ($identifiable) {
                            $coll->add($record);
                            unset($prevRow);
                        }
                    } else {

                        $relation    = $map['relation'];
                        $parentAlias = $map['parent'];
                        $parentMap   = $this->_aliasMap[$parentAlias];
                        $parent      = $prev[$parentAlias]->getLast();

                        // check the type of the relation
                        if ($relation->isOneToOne()) {
                            if ( ! $identifiable) {
                                continue;
                            }
                            $prev[$alias] = $record;
                        } else {
                            // one-to-many relation or many-to-many relation
                            if ( ! $prev[$parentAlias]->getLast()->hasReference($relation->getAlias())) {
                                // initialize a new collection
                                $prev[$alias] = new Doctrine_Collection2($parentMap['table']);
                                $prev[$alias]->setReference($parent, $relation);
                            } else {
                                // previous entry found from memory
                                $prev[$alias] = $prev[$parentAlias]->getLast()->get($relation->getAlias());
                            }
                            // add record to the current collection
                            if ($identifiable) {
                                $prev[$alias]->add($record);
                            }
                        }
                        // initialize the relation from parent to the current collection/record
                        $parent->set($relation->getAlias(), $prev[$alias]);
                    }
    
                    // following statement is needed to ensure that mappings
                    // are being done properly when the result set doesn't
                    // contain the rows in 'right order'
    
                    if ($prev[$alias] !== $record) {
                        $prev[$alias] = $record;
                    }
                }
                $prevRow[$tableAlias] = $row;
            }
        }
        return $coll;
    }
    /**
     * isIdentifiable
     * returns whether or not a given data row is identifiable (it contains
     * all primary key fields specified in the second argument)
     *
     * @param array $row
     * @param mixed $primaryKeys
     * @return boolean
     */
    public function isIdentifiable(array $row, $primaryKeys)
    {
        if (is_array($primaryKeys)) {
            foreach ($primaryKeys as $id) {
                if ($row[$id] == null) {
                    return false;
                }
            }
        } else {
            if ( ! isset($row[$primaryKeys])) {
                return false;
            }
        }
        return true;
    }
    /**
     * getType
     *
     * returns the type of this query object
     * by default the type is Doctrine_Hydrate::SELECT but if update() or delete()
     * are being called the type is Doctrine_Hydrate::UPDATE and Doctrine_Hydrate::DELETE,
     * respectively
     *
     * @see Doctrine_Hydrate::SELECT
     * @see Doctrine_Hydrate::UPDATE
     * @see Doctrine_Hydrate::DELETE
     *
     * @return integer      return the query type
     */
    public function getType() 
    {
        return $this->type;
    }
    /**
     * applyInheritance
     * applies column aggregation inheritance to DQL / SQL query
     *
     * @return string
     */
    public function applyInheritance()
    {
        // get the inheritance maps
        $array = array();

        foreach ($this->tables as $alias => $table) {
            $array[$alias][] = $table->inheritanceMap;
        }

        // apply inheritance maps
        $str = "";
        $c = array();

        $index = 0;
        foreach ($array as $tableAlias => $maps) {
            $a = array();
            
            // don't use table aliases if the query isn't a select query
            if ($this->type !== Doctrine_Query::SELECT) {
                $tableAlias = '';
            } else {
                $tableAlias .= '.';
            }

            foreach ($maps as $map) {
                $b = array();
                foreach ($map as $field => $value) {
                    if ($index > 0) {
                        $b[] = '(' . $tableAlias  . $field . ' = ' . $value
                             . ' OR ' . $tableAlias . $field . ' IS NULL)';
                    } else {
                        $b[] = $tableAlias . $field . ' = ' . $value;
                    }
                }

                if ( ! empty($b)) {
                    $a[] = implode(' AND ', $b);
                }
            }

            if ( ! empty($a)) {
                $c[] = implode(' AND ', $a);
            }
            $index++;
        }

        $str .= implode(' AND ', $c);

        return $str;
    }
    /**
     * parseData
     * parses the data returned by statement object
     *
     * @param mixed $stmt
     * @return array
     */
    public function parseData($stmt)
    {
        $array = array();

        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /**
             * parse the data into two-dimensional array
             */
            foreach ($data as $key => $value) {
                $e = explode('__', $key);

                $field      = strtolower(array_pop($e));
                $tableAlias = strtolower(implode('__', $e));

                $data[$tableAlias][$field] = $value;

                unset($data[$key]);
            }
            $array[] = $data;
        }

        $stmt->closeCursor();
        return $array;
    }
    /**
     * returns a Doctrine_Table for given name
     *
     * @param string $name              component name
     * @return Doctrine_Table|boolean
     */
    public function getTable($name)
    {
        if (isset($this->tables[$name])) {
            return $this->tables[$name];
        }
        return false;
    }
    /**
     * @return string                   returns a string representation of this object
     */
    public function __toString()
    {
        return Doctrine_Lib::formatSql($this->getQuery());
    }
}
