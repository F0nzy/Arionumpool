<?php
#################  PID SYSTEM #################
$script_name = __FILE__;
$scripta = explode('/', $script_name);
$script_name = $scripta[count($scripta) - 1];
if (empty($script_name)) {
    exit;
}
$pid112 = '/var/run/'.$script_name.'.pid';
$pid_exists = file_exists($pid112);
$pid_time = 0;
if ($pid_exists) {
    $pid_time = filemtime($pid112);
    if (time() - $pid_time > 3600) {
        system("rm -rf $pid112");
    }
    die("\n\n### RUNNING ### -- PID: $pid112\n\n");
}
system("touch $pid112");
function shut_down()
{
    global $pid112;
    system("rm -rf $pid112");
    echo "\n# ShutDown #\n";

}

register_shutdown_function('shut_down');
###############################################

set_time_limit(0);
if (PHP_SAPI !== 'cli') {
    die('This should only be run as cli');
}

require_once __DIR__.'/db.php';

if ($pool_config['payout_history'] == null) {
    die('Payout History variable not set in config');
}


function pay_post($url, $data = [])
{
    global $pool_config;
    $peer = $pool_config['node_url'];
    $postdata = http_build_query(
        [
            'data' => json_encode($data),
            'coin' => ' arionum',
        ]
    );

    $opts = [
        'http' =>
            [
                'timeout' => '300',
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postdata,
            ],
    ];

    $context = stream_context_create($opts);

    $result = file_get_contents($peer.$url, false, $context);
    return json_decode($result, true);
}


echo "\n----------------------------------------------------------------------------------\n";
$current = $aro->single('SELECT height FROM blocks ORDER by height DESC LIMIT 1');
echo "Current block $current\n";

$r = $db->run(
    'SELECT DISTINCT block FROM payments WHERE height<:h AND done=0',
    [':h' => $current - 10]
);
if (count($r) === 0) {
    die("No payments pending\n");
}

// check for orphan blocks
foreach ($r as $x) {
    echo "Checking $x[block]\n";
    $s = $aro->single('SELECT COUNT(1) FROM blocks WHERE id=:id', [':id' => $x['block']]);
    if ($s === 0) {
// dit kunnen we dus aanpassen naar orphaned. block wordt niet nog een keer meegenomen want verdwijnt uit payments
        if ($pool_config['keep_orphans'] !== true) {
          $db->run('DELETE FROM blocks WHERE id=:id', [':id' => $x['block']]);
        }
// nu halen we de payments weg en wordt het block niet opnieuw geselecteerd bij de volgende payment cycle
        $db->run('DELETE FROM payments WHERE block=:id', [':id' => $x['block']]);
        echo "Deleted block: $x[block]\n";
    }
}

$total_paid = 0;
$r = $db->run(
    'SELECT SUM(val) as v, address FROM payments WHERE height<:h AND done=0 GROUP by address',
    [':h' => $current - 10]
);
foreach ($r as $x) {
    if ($x['v'] < $pool_config['min_payout']) {
        continue;
    }
    $fee = $x['v'] * 0.0025;
    if ($fee < 0.00000001) {
        $fee = 0.00000001;
    }
    if ($fee > 10) {
        $fee = 10;
    }
    $val = number_format($x['v'] - $fee, 8, '.', '');
    #$val=intval($val);
    $public_key = $pool_config['public_key'];
    $private_key = $pool_config['private_key'];
    $message = $pool_config['payout_message'];
    if ($message == null) {
       $message = $pool_config['pool_name'];
    }
    $res = pay_post('/api.php?q=send', [
        'dst' => $x['address'],
        'val' => $val,
        'private_key' => $private_key,
        'public_key' => $public_key,
        'version' => 1,
        'message' => $message,
    ]);
    echo "$val\n";
    echo "$x[address]\n";
    if ($res['status'] !== 'ok') {
        print("ERROR: $res[data]\n");
    } else {
        $total_paid += $x['v'];

        echo "Transaction sent - $x[address] - $val! Transaction id: $res[data]\n";
        $db->run(
            'UPDATE payments SET txn=:txn, done=1 WHERE address=:address AND height<:h AND done=0',
            [
                ':h' => $current - 10,
                ':txn' => $res['data'],
                ':address' => $x['address'],
            ]
        );
        $db->run('UPDATE miners  SET total_paid=total_paid + :h WHERE id=:p', [':h' => $x['v'], ':p' => $x['address']]);
        echo "DB updated\n";
    }
}

$old = $db->single("SELECT val FROM info WHERE id='total_paid'");
$new = $old + $total_paid;
echo "Total paid: $new\n";

$db->run("UPDATE info SET val=:s WHERE id='total_paid'", [':s' => $new]);
$not = $db->single('SELECT SUM(val) FROM payments WHERE done=0');
echo "Pending balance: $not\n";

$db->run('DELETE FROM payments WHERE done=1 AND height<:h', [':h' => $current - $pool_config['payout_history']]);

