--TEST--
Prepare with cursor buffered and fetch a variety of types converted to different types
--DESCRIPTION--
Test various conversion functionalites for buffered queries with PDO_SQLSRV.
--SKIPIF--
<?php require_once('skipif_mid-refactor.inc'); ?>
--FILE--
<?php
require_once("MsCommon_mid-refactor.inc");

$tableName = 'pdoFetchingClientBuffer';
$violation = 'Restricted data type attribute violation';
$outOfRange = 'Numeric value out of range';
$truncation = 'Fractional truncation';
$epsilon = 0.00001;

function fetchAsUTF8($conn, $tableName, $inputs)
{
    $query = "SELECT * FROM $tableName";
    try {
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        
        // Fetch all fields as UTF-8 strings
        for ($i = 0; $i < count($inputs); $i++) {
            $stmt->execute();
            $f = $stmt->fetchColumn($i);
            
            if ($f !== $inputs[$i]) {
                var_dump($f);
            }
        }
    } catch (PdoException $e) {
        echo "Caught exception in fetchAsUTF8:\n";
        echo $e->getMessage() . PHP_EOL;
    }
}

function fetchArray($conn, $tableName, $inputs)
{
    $query = "SELECT * FROM $tableName";
    try {
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        $stmt->execute();
        
        // By default, even numeric or datetime fields are fetched as strings
        $result = $stmt->fetch(PDO::FETCH_NUM);
        for ($i = 0; $i < count($inputs); $i++) {
            if ($result[$i] !== $inputs[$i]) {
                var_dump($f);
            }
        }
    } catch (PdoException $e) {
        echo "Caught exception in fetchArray:\n";
        echo $e->getMessage() . PHP_EOL;
    }
}

function fetchBinaryAsNumber($conn, $tableName, $inputs)
{
    global $violation;

    $query = "SELECT c1 FROM $tableName";

    try {
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED, PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE=>true));
        $stmt->execute();
        
        $stmt->bindColumn('c1', $binaryValue, PDO::PARAM_INT);
        $row = $stmt->fetch(PDO::FETCH_BOUND);
        echo "in fetchBinaryAsNumber: exception should have been thrown!\n";
    } catch (PdoException $e) {
        // The varbinary field - expect the violation error
        if (strpos($e->getMessage(), $violation) === false) {
            echo "in fetchBinaryAsNumber: expected '$violation' but caught this:\n";
            echo $e->getMessage() . PHP_EOL;
        }
    }
}

function fetchBinaryAsBinary($conn, $tableName, $inputs)
{
    try {
        $query = "SELECT c1 FROM $tableName";
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        $stmt->execute();
        
        $stmt->bindColumn('c1', $binaryValue, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        $row = $stmt->fetch(PDO::FETCH_BOUND);
    
        if ($binaryValue !== $inputs[0]) {
            echo "Fetched binary value unexpected: $binaryValue\n";
        }
    } catch (PdoException $e) {
        echo "Caught exception in fetchBinaryAsBinary:\n";
        echo $e->getMessage() . PHP_EOL;
    }
}

function fetchFloatAsInt($conn, $tableName)
{
    global $truncation;

    try {
        $query = "SELECT c3 FROM $tableName";
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        $stmt->execute();

        $stmt->bindColumn('c3', $floatValue, PDO::PARAM_INT);
        $row = $stmt->fetch(PDO::FETCH_BOUND);
        
        // This should return SQL_SUCCESS_WITH_INFO with the truncation error
        $info = $stmt->errorInfo();
        if ($info[0] != '01S07' || $info[2] !== $truncation) {
            print_r($stmt->errorInfo());
        }
    } catch (PdoException $e) {
        echo "Caught exception in fetchFloatAsInt:\n";
        echo $e->getMessage() . PHP_EOL;
    }
}

function fetchCharAsInt($conn, $tableName, $column)
{
    global $outOfRange;

    try {
        $query = "SELECT $column FROM $tableName";
        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        $stmt->execute();

        $stmt->bindColumn($column, $value, PDO::PARAM_INT);
        $row = $stmt->fetch(PDO::FETCH_BOUND);
        
        // TODO 11297: fix this part outside Windows later
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            echo "in fetchCharAsInt: exception should have been thrown!\n";
        } else {
            if ($value != 0) {
                var_dump($value);
            }
        }
    } catch (PdoException $e) {
        // The (n)varchar field - expect the outOfRange error
        if (strpos($e->getMessage(), $outOfRange) === false) {
            echo "in fetchCharAsInt ($column): expected '$outOfRange' but caught this:\n";
            echo $e->getMessage() . PHP_EOL;
        }
    }
}

function fetchAsNumerics($conn, $tableName, $inputs)
{
    // The following calls expect different errors
    fetchFloatAsInt($conn, $tableName);
    fetchCharAsInt($conn, $tableName, 'c6');
    fetchCharAsInt($conn, $tableName, 'c7');
    
    // The following should work
    try {
        $query = "SELECT c2, c4 FROM $tableName";
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

        $stmt = $conn->prepare($query, array(PDO::ATTR_CURSOR=>PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE=>PDO::SQLSRV_CURSOR_BUFFERED));
        $stmt->execute();
            
        $stmt->bindColumn('c2', $intValue, PDO::PARAM_INT);
        $stmt->bindColumn('c4', $decValue, PDO::PARAM_INT);
            
        $row = $stmt->fetch(PDO::FETCH_BOUND);
        
        if ($intValue !== intval($inputs[1])) {
            var_dump($intValue);
        }
        if ($decValue !== intval($inputs[3])) {
            var_dump($decValue);
        }
    } catch (PdoException $e) {
        echo "Caught exception in fetchAsNumerics:\n";
        echo $e->getMessage() . PHP_EOL;
    }
}

try {
    $conn = connect();
    $conn->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    
    $columns = array('c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7');
    $types = array('varbinary(10)', 'int', 'float(53)', 'decimal(16, 6)', 'datetime2', 'varchar(50)', 'nvarchar(50)');
    $inputs = array('abcdefghij', '34567', '9876.5432', '123456789.012340', '2020-02-02 20:20:20.2220000', 'This is a test', 'Şơмė śäოрŀề');

    // Create table
    $colMeta = array(new ColumnMeta($types[0], $columns[0]),
                     new ColumnMeta($types[1], $columns[1]),
                     new ColumnMeta($types[2], $columns[2]),
                     new ColumnMeta($types[3], $columns[3]),
                     new ColumnMeta($types[4], $columns[4]),
                     new ColumnMeta($types[5], $columns[5]),
                     new ColumnMeta($types[6], $columns[6]));
    createTable($conn, $tableName, $colMeta);

    // Prepare the input values and insert one row
    $query = "INSERT INTO $tableName VALUES(?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    for ($i = 0; $i < count($columns); $i++) {
        if ($i == 0) {
            $stmt->bindParam($i+1, $inputs[$i], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        } else {
            $stmt->bindParam($i+1, $inputs[$i]);
        }
    }
    $stmt->execute();
    unset($stmt);

    // Starting fetching using client buffers
    fetchAsUTF8($conn, $tableName, $inputs);
    fetchArray($conn, $tableName, $inputs);
    fetchBinaryAsNumber($conn, $tableName, $inputs);
    fetchBinaryAsBinary($conn, $tableName, $inputs);
    fetchAsNumerics($conn, $tableName, $inputs);
    
    // dropTable($conn, $tableName);
    echo "Done\n";
    unset($conn);
} catch (PdoException $e) {
    echo $e->getMessage() . PHP_EOL;
}
?>
--EXPECT--
Done