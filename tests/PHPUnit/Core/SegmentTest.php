<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
class SegmentTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        parent::setUp();

        // setup the access layer (required in Segment contrustor testing if anonymous is allowed to use segments)
        $pseudoMockAccess = new FakeAccess;
        FakeAccess::$superUser = true;
        Zend_Registry::set('access', $pseudoMockAccess);

        // Load and install plugins
        $pluginsManager = Piwik_PluginsManager::getInstance();
        $pluginsManager->loadPlugins(Piwik_Config::getInstance()->Plugins['Plugins']);
    }

    public function tearDown()
    {
        parent::tearDown();
        Piwik_PluginsManager::getInstance()->unloadPlugins();
    }

    protected function _filterWhitsSpaces($valueToFilter)
    {
        if (is_array($valueToFilter)) {
            foreach ($valueToFilter AS $key => $value) {
                $valueToFilter[$key] = $this->_filterWhitsSpaces($value);
            }
            return $valueToFilter;
        } else {
            return preg_replace('/[\s]+/', ' ', $valueToFilter);
        }
    }


    public function getCommonTestData()
    {
        return array(
            // Normal segment
            array('country==France', array(
                'where' => ' log_visit.location_country = ? ',
                'bind'  => array('France'))),

            // unescape the comma please
            array('country==a\,==', array(
                'where' => ' log_visit.location_country = ? ',
                'bind'  => array('a,=='))),

            // AND, with 2 values rewrites
            array('country==a;visitorType!=returning;visitorType==new', array(
                'where' => ' log_visit.location_country = ? AND log_visit.visitor_returning <> ? AND log_visit.visitor_returning = ? ',
                'bind'  => array('a', '1', '0'))),

            // OR, with 2 value rewrites
            array('referrerType==search,referrerType==direct', array(
                'where' => ' (log_visit.referer_type = ? OR log_visit.referer_type = ? )',
                'bind'  => array(Piwik_Common::REFERER_TYPE_SEARCH_ENGINE,
                                 Piwik_Common::REFERER_TYPE_DIRECT_ENTRY))),
        );
    }

    /**
     * @dataProvider getCommonTestData
     * @group Core
     * @group Segment
     */
    public function testCommon($segment, $expected)
    {
        $select = 'log_visit.idvisit';
        $from = 'log_visit';

        $expected = array(
            'sql'  => '
                SELECT
                    log_visit.idvisit
                FROM
                    ' . Piwik_Common::prefixTable('log_visit') . ' AS log_visit
                WHERE
                    ' . $expected['where'],
            'bind' => $expected['bind']
        );

        $segment = new Piwik_Segment($segment, $idSites = array());
        $sql = $segment->getSelectQuery($select, $from, false);

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($sql));

        // calling twice should give same results
        $sql = $segment->getSelectQuery($select, array($from));
        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($sql));

        $this->assertEquals(32, strlen($segment->getHash()));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryNoJoin()
    {
        $select = '*';
        $from = 'log_visit';
        $where = 'idsite = ?';
        $bind = array(1);

        $segment = 'customVariableName1==Test;visitorType==new';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_visit') . " AS log_visit
                WHERE
                    ( idsite = ? )
                    AND
                    ( log_visit.custom_var_k1 = ? AND log_visit.visitor_returning = ? )",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinVisitOnAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = 'log_link_visit_action.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitorType==new';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Piwik_Common::prefixTable('log_visit') . " AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit
                WHERE
                    ( log_link_visit_action.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_visit.visitor_returning = ? )",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinActionOnVisit()
    {
        $select = 'sum(log_visit.visit_total_actions) as nb_actions, max(log_visit.visit_total_actions) as max_actions, sum(log_visit.visit_total_time) as sum_visit_length';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitorType==new';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    sum(log_inner.visit_total_actions) as nb_actions, max(log_inner.visit_total_actions) as max_actions, sum(log_inner.visit_total_time) as sum_visit_length
                FROM
                    (
                SELECT
                    log_visit.visit_total_actions,
                    log_visit.visit_total_time
                FROM
                    " . Piwik_Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_visit.visitor_returning = ? )
                GROUP BY log_visit.idvisit
                    ) AS log_inner",
            "bind" => array(1, 'Test', 0));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinConversionOnAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = 'log_link_visit_action.idvisit = ?';
        $bind = array(1);

        $segment = 'customVariablePageName1==Test;visitConvertedGoalId==1;customVariablePageName2==Test2';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idlink_va = log_link_visit_action.idlink_va AND log_conversion.idsite = log_link_visit_action.idsite
                WHERE
                    ( log_link_visit_action.idvisit = ? )
                    AND
                    ( log_link_visit_action.custom_var_k1 = ? AND log_conversion.idgoal = ? AND log_link_visit_action.custom_var_k2 = ? )",
            "bind" => array(1, 'Test', 1, 'Test2'));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinActionOnConversion()
    {
        $select = '*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId!=2;customVariablePageName1==Test;visitConvertedGoalId==1';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion
                    LEFT JOIN " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_conversion.idlink_va = log_link_visit_action.idlink_va
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( log_conversion.idgoal <> ? AND log_link_visit_action.custom_var_k1 = ? AND log_conversion.idgoal = ? )",
            "bind" => array(1, 2, 'Test', 1));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinConversionOnVisit()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = 'log_visit.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Piwik_Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idvisit = log_visit.idvisit
                WHERE
                    ( log_visit.idvisit = ? )
                    AND
                    ( log_conversion.idgoal = ? )
                GROUP BY log_visit.idvisit
                    ) AS log_inner",
            "bind" => array(1, 1));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segemnt
     */
    public function testGetSelectQueryConversionOnly()
    {
        $select = 'log_conversion.*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_conversion.*
                FROM
                    " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( log_conversion.idgoal = ? )",
            "bind" => array(1, 1));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinVisitOnConversion()
    {
        $select = '*';
        $from = 'log_conversion';
        $where = 'log_conversion.idvisit = ?';
        $bind = array(1);

        $segment = 'visitConvertedGoalId==1,visitServerHour==12';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion
                    LEFT JOIN " . Piwik_Common::prefixTable('log_visit') . " AS log_visit ON log_conversion.idvisit = log_visit.idvisit
                WHERE
                    ( log_conversion.idvisit = ? )
                    AND
                    ( (log_conversion.idgoal = ? OR HOUR(log_visit.visit_last_action_time) = ? ))",
            "bind" => array(1, 1, 12));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * visit is joined on action, then conversion is joined
     * make sure that conversion is joined on action not visit
     *
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinVisitAndConversionOnAction()
    {
        $select = '*';
        $from = 'log_link_visit_action';
        $where = false;
        $bind = array();

        $segment = 'visitServerHour==12;visitConvertedGoalId==1';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    *
                FROM
                    " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action
                    LEFT JOIN " . Piwik_Common::prefixTable('log_visit') . " AS log_visit ON log_visit.idvisit = log_link_visit_action.idvisit
                    LEFT JOIN " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idlink_va = log_link_visit_action.idlink_va AND log_conversion.idsite = log_link_visit_action.idsite
                WHERE
                     HOUR(log_visit.visit_last_action_time) = ? AND log_conversion.idgoal = ? ",
            "bind" => array(12, 1));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * join conversion on visit, then actions
     * make sure actions are joined before conversions
     *
     * @group Core
     * @group Segment
     */
    public function testGetSelectQueryJoinConversionAndActionOnVisit()
    {
        $select = 'log_visit.*';
        $from = 'log_visit';
        $where = false;
        $bind = array();

        $segment = 'visitConvertedGoalId==1;visitServerHour==12;customVariablePageName1==Test';
        $segment = new Piwik_Segment($segment, $idSites = array());

        $query = $segment->getSelectQuery($select, $from, $where, $bind);

        $expected = array(
            "sql"  => "
                SELECT
                    log_inner.*
                FROM
                    (
                SELECT
                    log_visit.*
                FROM
                    " . Piwik_Common::prefixTable('log_visit') . " AS log_visit
                    LEFT JOIN " . Piwik_Common::prefixTable('log_link_visit_action') . " AS log_link_visit_action ON log_link_visit_action.idvisit = log_visit.idvisit
                    LEFT JOIN " . Piwik_Common::prefixTable('log_conversion') . " AS log_conversion ON log_conversion.idlink_va = log_link_visit_action.idlink_va AND log_conversion.idsite = log_link_visit_action.idsite
                WHERE
                     log_conversion.idgoal = ? AND HOUR(log_visit.visit_last_action_time) = ? AND log_link_visit_action.custom_var_k1 = ?
                GROUP BY log_visit.idvisit
                    ) AS log_inner",
            "bind" => array(1, 12, 'Test'));

        $this->assertEquals($this->_filterWhitsSpaces($expected), $this->_filterWhitsSpaces($query));
    }

    /**
     * Dataprovider for testBogusSegmentThrowsException
     */
    public function getBogusSegments()
    {
        return array(
            array('referrerType==not'),
            array('someRandomSegment==not'),
            array('A=B')
        );
    }

    /**
     * @group Core
     * @group Segment
     * @dataProvider getBogusSegments
     */
    public function testBogusSegmentThrowsException($segment)
    {
        try {
            $segment = new Piwik_Segment($segment, $idSites = array());
        } catch (Exception $e) {
            return;
        }
        $this->fail('Expected exception not raised');
    }
}
