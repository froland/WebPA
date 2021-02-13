<?php
/**
 * engCIS local version
 *
 * @copyright Loughborough University
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GPL version 3
 *
 * @link https://github.com/webpa/webpa
 */

namespace WebPA\includes\classes;

use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\ParameterType;
use WebPA\includes\functions\ArrayFunctions;
use WebPA\includes\functions\Common;
use WebPA\includes\functions\AcademicYear;
use function Doctrine\DBAL\Query\QueryBuilder;

include_once __DIR__ . '/../inc_global.php';

class EngCIS
{
    private $_DAO;
    private $_ordering_types;
    private $user;
    private $sourceId;
    private $moduleId;
    private $dbConn;

    /**
     * CONSTRUCTOR
     */
    public function __construct($sourceId, $moduleId)
    {
        $this->sourceId = $sourceId;
        $this->moduleId = $moduleId;

        $this->_DAO = new DAO(APP__DB_HOST, APP__DB_USERNAME, APP__DB_PASSWORD, APP__DB_DATABASE);
        $this->_DAO->set_debug(false);

        $this->dbConn = $this->_DAO->getConnection();
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /*
    * --------------------------------------------------------------------------------
    * Module Methods
    * --------------------------------------------------------------------------------
    */

    /**
     * Get module info as an array
     *
     * @param string/array $module_id module ID(s) to search for
     * @param string $ordering ordering mode
     *
     * @return array  either an assoc-array of module info or an array of assoc-arrays, containing many modules' info
     */
    function get_module($modules = null, $ordering = 'id')
    {
        // If there's more than one module to search for, get all the rows
        if (is_array($modules)) {
            // get all modules
            $queryBuilder = $this->dbConn->createQueryBuilder();

            $queryBuilder
                ->select('lcm.module_id', 'lcm.module_title', 'lcm.module_code')
                ->from(APP__DB_TABLE_PREFIX . 'module lcm')
                ->where('lcm.source_id = :source_id')
                ->andWhere('module_id IN :modules')
                ->setParameter(':source_id', $this->sourceId)
                ->setParameter(':modules', $modules, $this->dbConn::PARAM_INT_ARRAY);

            if ($ordering === 'name') {
                $queryBuilder->orderBy('lcm.module_id');
            } else {
                $queryBuilder->orderBy('lcm.module_title');
            }

            return $queryBuilder->execute()->fetchAllAssociative();
        } else if (!empty($modules)) {  // else, just return one row
            $moduleQuery = 'SELECT module_id, module_title, module_code FROM ' . APP__DB_TABLE_PREFIX . 'module WHERE source_id = ? AND module_id IN ? LIMIT 1';

            return $this->dbConn->fetchAssociative($moduleQuery, [$this->sourceId, $modules], [ParameterType::STRING, $dbConn::PARAM_INT_ARRAY]);
        } else if ($this->user->is_admin()) {
            return $this->_DAO->fetch("SELECT lcm.module_id, lcm.module_title, lcm.module_code
                    FROM " . APP__DB_TABLE_PREFIX . "module lcm
                    WHERE (lcm.source_id = '{$this->sourceId}')
                    $order_by_clause");
        } else {
            return $this->_DAO->fetch("SELECT lcm.module_id, lcm.module_title, lcm.module_code
                  FROM " . APP__DB_TABLE_PREFIX . "module lcm
                  INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module lcsm ON lcm.module_id = lcsm.module_id
                  WHERE (lcsm.user_type = '" . APP__USER_TYPE_TUTOR . "') AND
                        (user_id = $this->user->id) AND (lcm.source_id = '{$this->sourceId}')
                  $order_by_clause");
        }
    }// /->get_module()

    /**
     * Get all the module info as an array
     *
     * @return array
     */
    function get_all_modules()
    {
        return $this->dbConn->fetchAllAssociative('SELECT lcm.module_id, lcm.module_title FROM ' . APP__DB_TABLE_PREFIX . 'module lcm');
    }

    /**
     * Get array of staff for module
     * @param integer $module_id
     * @param string $ordering
     * @return array
     */
    function get_module_staff($module_id, $ordering)
    {
        $order_by_clause = $this->_order_by_clause('staff', $ordering);

        return $this->_DAO->fetch("SELECT lcs.*
                  FROM " . APP__DB_TABLE_PREFIX . "user lcs
                  INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module lcsm ON lcs.user_id = lcsm.user_id
                  WHERE lcsm.user_type = '" . APP__USER_TYPE_TUTOR . "'
                    AND module_id = $module_id
                  $order_by_clause");
    }// /->get_module_staff()

    /**
     * Get array of students for one or more modules
     * @param integer $modules
     * @param string $ordering
     * @return array
     */
    function get_module_students($module, $ordering = 'name')
    {
        $order_by_clause = $this->_order_by_clause('student', $ordering);

        $sql = "SELECT DISTINCT lcs.*, lcs.id_number AS student_id
                  FROM " . APP__DB_TABLE_PREFIX . "user lcs
                  INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module lcsm ON lcs.user_id = lcsm.user_id AND lcsm.module_id = $module
                  WHERE lcsm.user_type = '" . APP__USER_TYPE_STUDENT . "'
                  $order_by_clause
                  ";
        return $this->_DAO->fetch($sql);
    }// /->get_module_students()

    /**
     * Get total number of students on one or more modules
     *
     * @param integer $module module to count students for
     * @return integer
     */
    function get_module_students_count($module)
    {
        $sql = "SELECT COUNT(DISTINCT u.user_id)
        FROM " . APP__DB_TABLE_PREFIX . "user u
            INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module um ON u.user_id = um.user_id
        WHERE um.module_id = $module
          AND um.user_type = '" . APP__USER_TYPE_STUDENT . "'";
        return $this->_DAO->fetch_value($sql);
    }// /->get_module_students_count

    /**
     * Get an array of student IDs for students on the given modules
     * @param array $modules modules to count students for
     * @return array
     */
    function get_module_students_id($modules)
    {
        if (!empty($modules)) {
            $module_set = $this->_DAO->build_set((array)$modules, false);
            return $this->_DAO->fetch_col("SELECT DISTINCT u.id_number AS staff_id
                      FROM " . APP__DB_TABLE_PREFIX . "user u
                      INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module um ON u.user_id = um.user_id
                      WHERE um.module_id IN $module_set
                        AND um.user_type = '" . APP__USER_TYPE_STUDENT . "'
                      ORDER BY u.user_id ASC");
        }
    }// /->get_module_students_id()

    /**
     * Get an array of user IDs for students on the given modules (user_id = 'student_{studentID}'
     * @param array $modules modules to count students for
     * @return array
     */
    function get_module_students_user_id($modules)
    {
        if (!empty($modules)) {
            $module_set = $this->_DAO->build_set((array)$modules, false);
            $sql = "SELECT DISTINCT u.user_id
          FROM " . APP__DB_TABLE_PREFIX . "user u
            INNER JOIN " . APP__DB_TABLE_PREFIX . "user_module um ON u.user_id = um.user_id
          WHERE um.module_id IN $module_set
            AND um.user_type = '" . APP__USER_TYPE_STUDENT . "'
          ORDER BY u.user_id ASC
          ";
            return $this->_DAO->fetch_col($sql);
        }
    }// /->get_module_students_user_id()

    /**
     * Get number of students on individual multiple modules, grouped by module
     *
     * @param array $modules modules to count students for
     * @return array
     */
    function get_module_grouped_students_count($modules)
    {
        $module_search = $this->_DAO->build_filter('module_id', (array)$modules, 'OR');

        return $this->_DAO->fetch_assoc("SELECT module_id, COUNT(user_id)
                    FROM " . APP__DB_TABLE_PREFIX . "user_module lcsm
                    WHERE $module_search
                    GROUP BY module_id
                    ORDER BY module_id");
    }// ->get_modules_grouped_students_count()

    /*
    * --------------------------------------------------------------------------------
    * Staff Methods
    * --------------------------------------------------------------------------------
    */

    /**
     * Get array of modules for the given staff member(s)
     * Can work with either staff_id or staff_username alone (staff_id takes precedent)
     *
     * @param string/array $staff_id staff ID(s) to search for (use NULL if searching on username)
     * @param string/array $staff_username staff username(s) to search for
     * @param string $ordering ordering mode
     *
     * @return array  an array of assoc-arrays, containting many module info
     */
    function get_staff_modules($staff_id, $staff_username = null, $ordering = 'id')
    {

        return $this->get_user_modules($staff_id, $staff_username, $ordering);

    }// /->get_staff_modules

    /**
     * Is the given staff member associated with the given modules?
     *
     * @param string $staff_id staff id of member being checked
     * @param string/array $module_id  either a single module_id, or an array of module_ids
     * @return integer
     */
    function staff_has_module($staff_id, $module_id)
    {
        $module_id = (array)$module_id;
        $staff_modules = $this->get_staff_modules($staff_id);
        if (!$staff_modules) {
            return false;
        } else {
            $arr_module_id = ArrayFunctions::array_extract_column($staff_modules, 'module_id');
            $diff = array_diff($module_id, $arr_module_id);

            // If the array is empty, then the staff member has those modules
            return (count(array_diff($module_id, $arr_module_id)) === 0);
        }
    }// /->staff_has_module()

    /*
    * --------------------------------------------------------------------------------
    * Student Methods
    * --------------------------------------------------------------------------------
    */

    /**
     * Get array of modules for the given student(s)
     * Can work with either student_id or student_username alone (student_id takes precedent)
     *
     * @param string/array $student_id student ID(s) to search for (use NULL if searching on username)
     * @param string/array $student_username student username(s) to search for
     * @param string $ordering ordering mode
     *
     * @return array an array of module info arrays
     */
    function get_student_modules($student_id, $student_username = null, $ordering = 'id')
    {

        return $this->get_user_modules($student_id, $student_username, $ordering);

    }// /->get_student_modules()

    /*
    * --------------------------------------------------------------------------------
    * User Methods
    * --------------------------------------------------------------------------------
    */

    /**
     * Get a user's info.
     *
     * @param string|array $user_id
     * @param string $ordering
     *
     * @return object
     */
    function get_user($user_id, $ordering = 'name')
    {
        $user_set = $this->_DAO->build_set($user_id, false);

        if (is_array($user_id)) {
            $order_by_clause = $this->_order_by_clause('user', $ordering);

            $sql = "SELECT u.*, um.user_type
              FROM " . APP__DB_TABLE_PREFIX . "user u
              LEFT OUTER JOIN " . APP__DB_TABLE_PREFIX . "user_module um
              ON u.user_id = um.user_id
              WHERE (u.user_id IN {$user_set})
              AND (um.module_id = {$this->moduleId})
              $order_by_clause";

            return $this->_DAO->fetch($sql);
        } else {
            $query = 'SELECT u.*, um.user_type FROM ' . APP__DB_TABLE_PREFIX . 'user u '
                   . 'LEFT OUTER JOIN ' . APP__DB_TABLE_PREFIX . 'user_module um '
                   . 'ON u.user_id = um.user_id '
                   . 'WHERE u.user_id = ? '
                   . 'AND um.module_id = ? '
                   . 'OR u.admin = 1 '
                   . 'LIMIT 1';

            $parameterType = is_array($user_id) ? $this->dbConn::PARAM_INT_ARRAY : ParameterType::INTEGER;

            return $this->dbConn->fetchAssociative($query, [$user_id, $this->moduleId], [$parameterType, ParameterType::INTEGER]);
        }
    }// /->get_user()

    /**
     * Get a user's info by searching on email address
     *
     * @param string $email email address to search for
     *
     * Returns : an assoc-array of user info
     */
    function get_user_for_email($email)
    {
        $query = 'SELECT * FROM ' . APP__DB_TABLE_PREFIX . 'user WHERE email = ? LIMIT 1';

        return $this->dbConn->fetchAssociative($query, [$email], [ParameterType::STRING]);
    }

    /**
     * Get a user's info by searching on username
     *
     * @param string $username username to search for
     *
     * Returns : an assoc-array of user info
     */
    function get_user_for_username($username, $source_id = NULL)
    {

        if (is_null($source_id) && isset($_SESSION['_source_id'])) {
            $source_id = $_SESSION['_source_id'];
        } else if (is_null($source_id)) {
            $source_id = '';
        }
        $this->moduleId = Common::fetch_SESSION('_module_id', null);

        $query = 'SELECT u.*, um.user_type FROM ' . APP__DB_TABLE_PREFIX . 'user u '
               . 'LEFT OUTER JOIN ( '
               . 'SELECT * FROM ' . APP__DB_TABLE_PREFIX . 'user_module WHERE module_id = ? '
               . ') um '
               . 'ON u.user_id = um.user_id '
               . 'WHERE username = ? '
               . 'AND source_id = ?';

        return $this->dbConn->fetchAssociative($query, [$this->moduleId, $username, $source_id], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]);
    }

    /**
     * Get array of modules for the given user(s)
     * Can work with either user_id or username alone (user_id takes precedent)
     *
     * @param string/array $user_id user ID(s) to search for (use NULL if searching on username)
     * @param string/array $username username(s) to search for (use NULL for admin)
     * @param string $ordering ordering mode
     *
     * @return array an array of module info arrays
     */
    function get_user_modules($user_id, $username = NULL, $ordering = 'id', $source_id = NULL)
    {
        $dbConn = $this->_DAO->getConnection();
        $queryBuilder = $dbConn->createQueryBuilder();

        if (is_null($source_id) && isset($_SESSION['_source_id'])) {
            $source_id = $_SESSION['_source_id'];
        } else if (is_null($source_id)) {
            $source_id = '';
        }

        $queryBuilder->orderBy($ordering === 'name' ? 'lcm.module_title' : 'lcm.module_id');

        if ($user_id) {
            $user_set = $this->_DAO->build_set($user_id, false);

            $queryBuilder
                ->select('lcm.module_id', 'lcm.module_title', 'lcm.module_code', 'lcsm.user_type')
                ->from(APP__DB_TABLE_PREFIX . 'module', 'lcm')
                ->innerJoin('lcm', 'user_module', 'lcsm', 'lcm.module_id = lcsm.module_id')
                ->innerJoin('lcsm', 'user', 'u', 'lcsm.user_id = u.user_id')
                ->where(
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->or(
                            $queryBuilder->expr()->eq('lcm.source_id', '?'),
                            $queryBuilder->expr()->neq('u.source_id', '')
                        ),
                        $queryBuilder->expr()->in('lcsm.user_id', '?')
                    )
                );

            $queryBuilder->setParameter(0, $source_id, ParameterType::STRING);
            $queryBuilder->setParameter(1, $user_set, $dbConn::PARAM_INT_ARRAY);
        } else if ($username) {
            $user_set = $this->_DAO->build_set($username);

            $queryBuilder
                ->select('lcm.module_id', 'lcm.module_title', 'lcm.module_code', 'lcsm.user_type')
                ->from(APP__DB_TABLE_PREFIX . 'module lcm')
                ->innerJoin('lcm', APP__DB_TABLE_PREFIX . 'user_module', 'lcsm', 'lcm.module_id = lcsm.module_id')
                ->innerJoin('lcsm', 'user', 'u', 'lcsm.user_id = u.user_id')
                ->where('u.source_id = ?')
                ->andWhere('u.username IN (?)');

            $queryBuilder->setParameter(0, $source_id);
            $queryBuilder->setParameter(1, $user_set, $dbConn::PARAM_INT_ARRAY);
        } else {
            $queryBuilder
                ->select('lcm.module_id', 'lcm.module_title', 'lcm.module_code', '"' . APP__USER_TYPE_ADMIN . '" as user_type')
                ->from(APP__DB_TABLE_PREFIX . 'module lcm')
                ->where('lcm.source_id = ?');

            $queryBuilder->setParameter(0, $source_id);
        }

        return $queryBuilder->execute()->fetchAllAssociative();
    }

    /*
    * ================================================================================
    * Private Methods
    * ================================================================================
    */

    /**
     * Return an ORDER BY clause matching the given parameters
     *
     * @param string $row_type type of row being ordered. ['course','module','staff','student']
     * @param string $ordering type of ordering to do. ['id','name']
     *
     * @return string  SQL ORDER BY clause of the form 'ORDER BY fieldname' or NULL if row_type/ordering are invalid
     */
    function _order_by_clause($row_type, $ordering = null)
    {
        if (!is_array($this->_ordering_types)) {
            // All available ordering types
            $this->_ordering_types = array(
                'module' => array(
                    'id' => 'lcm.module_id',
                    'name' => 'lcm.module_title',
                ),
                'staff' => array(
                    'id' => 'lcs.user_id',
                    'name' => 'lcs.lastname, lcs.forename',
                ),
                'student' => array(
                    'id' => 'lcs.user_id',
                    'name' => 'lcs.lastname, lcs.forename',
                ),
                'user' => array(
                    'id' => 'u.user_id',
                    'name' => 'u.lastname, u.forename',
                ),
            );
        }

        if ((array_key_exists($row_type, $this->_ordering_types)) && (array_key_exists($ordering, $this->_ordering_types["$row_type"]))) {
            return 'ORDER BY ' . $this->_ordering_types["$row_type"]["$ordering"];
        } else {
            return null;
        }
    }// /->_order_by_clause()

    function get_user_academic_years($user_id = null)
    {
        if (!empty($user_id)) {
            $query = 'SELECT MIN(a.open_date) first, MAX(a.open_date) last ' .
                'FROM ' . APP__DB_TABLE_PREFIX . 'assessment a ' .
                'INNER JOIN ' . APP__DB_TABLE_PREFIX . 'module m ON a.module_id = m.module_id ' .
                'WHERE m.source_id = ? AND m.module_id = ?';

            $dates = $this->dbConn->fetchAssociative($query, [$this->sourceId, $this->moduleId], [ParameterType::STRING, ParameterType::INTEGER]);
        } else {
            $query = 'SELECT MIN(a.open_date) first, MAX(a.open_date) last ' .
                'FROM ' . APP__DB_TABLE_PREFIX . 'assessment a ' .
                'INNER JOIN ' . APP__DB_TABLE_PREFIX . 'module m ON a.module_id = m.module_id ' .
                'WHERE m.source_id = ?';

            $dates = $this->dbConn->fetchAssociative($query, [$this->sourceId], [ParameterType::STRING]);
        }

        // Ensure that the first record contains some dates as we could return a null record
        if (!empty($dates['first'])) {
            $years[] = AcademicYear::dateToYear(strtotime($dates['first']));
            $years[] = AcademicYear::dateToYear(strtotime($dates['last']));
        } else {
            $years[] = AcademicYear::dateToYear(time());
            $years[] = $years[0];
        }

        return $years;
    }

}// /class: EngCIS

?>
