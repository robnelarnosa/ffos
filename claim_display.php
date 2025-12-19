<?php
require_once 'auth_terminal.php';

if ($_SESSION['terminal_type'] !== 'CLAIM') {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// Initial load: today's IN_PROCESS and READY_FOR_CLAIM
$stmt = $pdo->prepare("
    SELECT id, display_number, status, updated_at
    FROM orders
    WHERE DATE(created_at) = CURDATE()
      AND status IN ('IN_PROCESS', 'READY_FOR_CLAIM')
    ORDER BY updated_at ASC, id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$inProcess = [];
$readyForClaim = [];

foreach ($rows as $r) {
    $id    = (int)$r['id'];
    $disp  = $r['display_number'] !== null ? (int)$r['display_number'] : $id;
    $dispStr = str_pad($disp, 4, '0', STR_PAD_LEFT);
    $status = strtoupper(trim($r['status'] ?? ''));

    $entry = [
        'id'      => $id,
        'display' => $dispStr,
        'status'  => $status,
    ];

    if ($status === 'READY_FOR_CLAIM') {
        $readyForClaim[] = $entry;
    } else {
        $inProcess[] = $entry;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Order Claim Display</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-main: #020617;        /* very dark navy */
            --bg-panel: #020617;
            --bg-panel-muted: #020617;
            --yellow-claim: #facc15;   /* bright yellow */
            --cyan-process: #22c55e;   /* greenish for "in process" */
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: var(--bg-main);
            color: var(--text-main);
            overflow: hidden;
        }

        .navbar {
            background: #000;
        }

        .screen-title {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: 0.08em;
        }

        .main-wrapper {
            padding: 0.75rem 1.25rem 1.25rem;
        }

        .panel {
            background: var(--bg-panel);
            border-radius: 0.75rem;
            border: 1px solid #1f2937;
            box-shadow: 0 10px 25px rgba(15,23,42,0.9);
            height: calc(100vh - 70px);
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 0.75rem 1rem 0.25rem;
            border-bottom: 1px solid #111827;
        }

        .panel-title {
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .panel-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .panel-body {
            flex: 1;
            padding: 0.75rem 0.75rem 0.75rem;
            overflow-y: auto;
        }

        .panel-body::-webkit-scrollbar {
            width: 8px;
        }
        .panel-body::-webkit-scrollbar-track {
            background: #020617;
        }
        .panel-body::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 999px;
        }

        /* Claim tiles (left) */
        .claim-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .claim-tile-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .claim-tile {
            min-width: 160px;
            min-height: 130px;
            border-radius: 0.75rem;
            padding: 0.4rem 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            letter-spacing: 0.1em;
            background: var(--yellow-claim);
            color: #111827;
            box-shadow: 0 15px 35px rgba(250,204,21,0.45);
            border: 2px solid #fef3c7;
            font-size: 2.2rem;
        }

        .claim-btn {
            margin-top: 0.25rem;
            font-size: 0.75rem;
            padding: 0.2rem 0.7rem;
            border-radius: 999px;
        }

        /* In-process tiles (right) */
        .process-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }

        .process-tile {
            min-width: 120px;
            min-height: 90px;
            border-radius: 0.75rem;
            padding: 0.3rem 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            letter-spacing: 0.08em;
            background: radial-gradient(circle at top left, #22c55e, #065f46);
            color: #ecfdf5;
            box-shadow: 0 12px 30px rgba(16,185,129,0.4);
            border: 1px solid #bbf7d0;
            font-size: 1.6rem;
        }

        .empty-message {
            text-align: center;
            margin-top: 2.5rem;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        @media (min-width: 1200px) {
            .claim-tile {
                min-width: 190px;
                min-height: 150px;
                font-size: 2.8rem;
            }
            .process-tile {
                min-width: 140px;
                min-height: 100px;
                font-size: 1.8rem;
            }
        }

        @media (max-width: 991.98px) {
            .panel {
                height: auto;
                margin-bottom: 0.75rem;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark mb-0">
    <div class="container-fluid">
        <span class="navbar-brand screen-title">ORDER CLAIM DISPLAY</span>
        <div class="ms-auto d-flex align-items-center">
            <span class="navbar-text text-light me-3">
                <?= htmlspecialchars($_SESSION['employee_name']) ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="main-wrapper">
    <div class="row g-3">
        

        <!-- IN PROCESS (right, smaller) -->
        <div class="col-lg-5 col-md-12">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">IN PROCESS</div>
                    <div class="panel-sub">These orders are currently being prepared.</div>
                </div>
                <div class="panel-body" id="inProcessContainer">
                    <?php if ($inProcess): ?>
                        <div class="process-grid">
                            <?php foreach ($inProcess as $o): ?>
                                <div class="process-tile"
                                     data-order-id="<?= (int)$o['id'] ?>">
                                    <?= htmlspecialchars($o['display']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-message">
                            No orders in process.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
		<!-- CLAIM NOW (left, bigger) -->
        <div class="col-lg-7 col-md-12">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">CLAIM NOW</div>
                    <div class="panel-sub text-warning">When your number appears here, proceed to the counter.</div>
                </div>
                <div class="panel-body" id="readyContainer">
                    <?php if ($readyForClaim): ?>
                        <div class="claim-grid">
                            <?php foreach ($readyForClaim as $o): ?>
                                <div class="claim-tile-wrapper">
                                    <div class="claim-tile"
                                         data-order-id="<?= (int)$o['id'] ?>"
                                         data-display="<?= htmlspecialchars($o['display']) ?>">
                                        <?= htmlspecialchars($o['display']) ?>
                                    </div>
                                    <button class="btn btn-success claim-btn"
                                            onclick="markClaimed(<?= (int)$o['id'] ?>, '<?= htmlspecialchars($o['display']) ?>')">
                                        MARK AS CLAIMED
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-message">
                            No orders ready for claim.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let ws = null;

document.addEventListener('DOMContentLoaded', () => {
    initWebSocket();
});

// Function to play beep sound for 10 seconds
function playBeepForReadyOrders() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);

        oscillator.frequency.setValueAtTime(800, audioContext.currentTime); // 800 Hz beep
        oscillator.type = 'square';

        gainNode.gain.setValueAtTime(0.3, audioContext.currentTime); // Moderate volume
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 10); // Fade out over 10 seconds

        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 10);
    } catch (e) {
        console.error('Error playing beep:', e);
        // Fallback: try to play a system beep if available
        try {

function initWebSocket() {
    try {
        const loc = window.location;
        const wsUrl = (loc.protocol === 'https:' ? 'wss://' : 'ws://') + loc.hostname + ':8080';
        ws = new WebSocket(wsUrl);

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'order_created' || data.type === 'order_updated') {
                    reloadOrders();
                }
            } catch (e) {
                console.error('Bad WS message', e);
            }
        };
        ws.onclose = () => {
            setTimeout(initWebSocket, 3000);
        };
    } catch (e) {
        console.error('WS init error', e);
    }
}

function reloadOrders() {
    fetch('api_get_claim_orders_today.php')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const inProc = res.in_process || [];
            const ready  = res.ready_for_claim || [];

            const inProcContainer = document.getElementById('inProcessContainer');
            const readyContainer  = document.getElementById('readyContainer');

            // READY FOR CLAIM
            if (!ready.length) {
                readyContainer.innerHTML =
                    '<div class="empty-message">No orders ready for claim.</div>';
            } else {
                let html = '<div class="claim-grid">';
                ready.forEach(o => {
                    const disp = escapeHtml(o.display_number_str);
                    html += `
                        <div class="claim-tile-wrapper">
                            <div class="claim-tile"
                                 data-order-id="${o.id}"
                                 data-display="${disp}">
                                ${disp}
                            </div>
                            <button class="btn btn-dark claim-btn"
                                    onclick="markClaimed(${o.id}, '${disp}')">
                                Claimed
                            </button>
                        </div>
                    `;
                });
                html += '</div>';
                readyContainer.innerHTML = html;
            }

            // IN PROCESS
            if (!inProc.length) {
                inProcContainer.innerHTML =
                    '<div class="empty-message">No orders in process.</div>';
            } else {
                let html = '<div class="process-grid">';
                inProc.forEach(o => {
                    const disp = escapeHtml(o.display_number_str);
                    html += `
                        <div class="process-tile" data-order-id="${o.id}">
                            ${disp}
                        </div>
                    `;
                });
                html += '</div>';
                inProcContainer.innerHTML = html;
            }

            // Play beep if new orders are ready
            if (ready.length > previousReadyCount) {
                playBeepForReadyOrders();
            }
            previousReadyCount = ready.length;
        })
        .catch(err => console.error('reloadOrders error', err));
}

function markClaimed(orderId, displayNumber) {
    if (!confirm('Mark order #' + displayNumber + ' as CLAIMED?')) return;

    const fd = new FormData();
    fd.append('order_id', orderId);

    fetch('api_claim_mark_claimed.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.error || 'Error marking as claimed.');
                return;
            }
            reloadOrders();
        })
        .catch(() => {
            alert('Network error marking as claimed.');
        });
}

function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
</body>
</html>
