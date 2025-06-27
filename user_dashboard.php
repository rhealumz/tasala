<?php
    require_once 'auth_check.php';
    require_once 'db_connect.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }


    if ($_SESSION['user_role'] == 1) {
        header("Location: admin_dashboard.php");
        exit();
    }

    $userId = $_SESSION['user_id'];

    // Get user data - updated to ensure we get first_name and last_name
    $userQuery = "SELECT u.*, r.role_name as role_name 
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.id = ?";
    $stmt = $conn->prepare($userQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $userData = $userResult->fetch_assoc();

    // Current active session
    $currentSessionQuery = "SELECT * FROM sessions 
                        WHERE user_id = ? AND is_active = 1
                        ORDER BY time_in DESC LIMIT 1";
    $stmt = $conn->prepare($currentSessionQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $currentSessionResult = $stmt->get_result();
    $currentSession = $currentSessionResult->fetch_assoc();

    // Get all sessions and bookings in one query - updated to include updated_at for bookings
    $bookingsQuery = "(
        SELECT id, user_id, room_type, time_in, time_out, 
            duration_minutes as duration, 
            amount_paid, 'session' as type, is_active,
            NULL as status, NULL as updated_at
        FROM sessions 
        WHERE user_id = ?
    ) 
    UNION ALL
    (
        SELECT id, user_id, room_type, time_in, time_out, 
            duration_hours*60 as duration, 
            amount_paid, 'booking' as type, 
            CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END as is_active,
            status, updated_at
        FROM bookings 
        WHERE user_id = ?
    )
    ORDER BY time_out DESC LIMIT 15";

    $statsQuery = "SELECT 
        (SELECT IFNULL(SUM(duration_minutes)/60, 0) FROM sessions WHERE user_id = ? AND time_out < NOW()) AS completed_session_hours,
        (SELECT IFNULL(SUM(duration_hours), 0) FROM bookings WHERE user_id = ? AND status = 'completed') AS completed_booking_hours,
        (SELECT COUNT(*) FROM sessions WHERE user_id = ? AND time_out < NOW()) AS completed_session_count,
        (SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'completed') AS completed_booking_count";

    $stmt = $conn->prepare($statsQuery);
    $stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
    $stmt->execute();
    $statsResult = $stmt->get_result();
    $stats = $statsResult->fetch_assoc();

    $totalHours = $stats['completed_session_hours'] + $stats['completed_booking_hours'];
    $totalSessions = $stats['completed_session_count'] + $stats['completed_booking_count'];

    $stmt = $conn->prepare($bookingsQuery);
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $allSessions = [];
    while ($row = $result->fetch_assoc()) {
        $allSessions[] = $row;
    }
    
    // Auto-cancel pending bookings whose start time has passed
$conn->query("UPDATE bookings SET status = 'cancelled' WHERE status = 'pending' AND NOW() > time_in AND user_id = $userId");

// Fetch unread notifications
$notifResult = $conn->query("SELECT * FROM notifications WHERE user_id = $userId AND is_read = 0 ORDER BY created_at DESC");
$notifications = $notifResult->fetch_all(MYSQLI_ASSOC);
// Mark notifications as read after displaying
if (!empty($notifications)) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $userId AND is_read = 0");
}
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>User Dashboard | <?= htmlspecialchars($userData['first_name'] . ' ' . htmlspecialchars($userData['last_name'])) ?></title>
        <!-- Bootstrap & FontAwesome -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body {
    min-height: 100vh;
    font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
    /* Soft blue gradient background */
    background: linear-gradient(135deg, #f4f8fb 0%, #eaf0fa 100%) !important;
}
.card, .dashboard-card {
    background: #fff;
    border-radius: 1.5rem;
    box-shadow: 0 6px 32px 0 rgba(30,60,114,0.08), 0 1.5px 4px 0 rgba(42,82,152,0.08);
    margin-bottom: 2rem;
}
.dashboard-header {
    background: #fff;
    color: #1e3c72;
    border-radius: 1.5rem 1.5rem 0 0;
    padding: 1.5rem 2.5rem;
    margin-bottom: 0;
    font-weight: bold;
    font-size: 1.5rem;
    box-shadow: 0 2px 8px 0 rgba(30,60,114,0.08);
}
.nav-pills .nav-link.active {
    background: linear-gradient(90deg, #1e3c72 60%, #2a5298 100%);
    color: #fff;
}
.amount-display {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e3c72;
}
            .profile-img {
                width: 150px;
                height: 150px;
                object-fit: cover;
            }
            .current-session-card {
                border-left: 5px solid #28a745;
            }
            .session-list {
                max-height: 500px;
                overflow-y: auto;
            }
            .session-card {
                transition: all 0.3s;
                margin-bottom: 10px;
            }
            .session-card:hover {
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
            .active-session {
                border-left: 4px solid #28a745;
            }
            .pending-booking {
                border-left: 4px solid #ffc107;
            }
            .completed-session {
                border-left: 4px solid #6c757d;
                opacity: 0.8;
            }
            .nav-pills .nav-link.active {
                background-color: #0d6efd;
            }
            .user-name {
                font-size: 1.5rem;
                font-weight: bold;
                margin-bottom: 0.5rem;
            }

            .stats-box {
        border-radius: 8px;
    }
    .stats-box h6 {
        font-size: 0.9rem;
        color: #6c757d;
    }
    .stats-box h4 {
        font-weight: bold;
    }

        </style>
    </head>
    <body>
        <?php if (isset($_SESSION['booking_success'])): ?>
    <div class="alert alert-success shadow-sm" id="booking-alert">
        <?= $_SESSION['booking_success']; ?>
    </div>
    <?php unset($_SESSION['booking_success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['booking_error'])): ?>
    <div class="alert alert-danger shadow-sm" id="booking-alert">
        <?= $_SESSION['booking_error']; ?>
    </div>
    <?php unset($_SESSION['booking_error']); ?>
<?php endif; ?>

        <?php include 'user_nav.php'; ?>

        <div class="container my-5">
            <div class="row">
                <!-- Profile Column -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-body text-center">
                <img src="<?= 
                    $userData['profile_picture'] ? 'uploads/' . htmlspecialchars($userData['profile_picture']) : 
                    'https://via.placeholder.com/150' 
                ?>" 
                alt="Profile" class="profile-img rounded-circle mb-3">
                
                <!-- Display user's full name -->
                <div class="user-name">
                    <?= htmlspecialchars($userData['first_name'] . ' ' . htmlspecialchars($userData['last_name'])) ?>
                </div>
                
                <p class="text-muted mb-1">@<?= htmlspecialchars($userData['username']) ?></p>
                <span class="badge bg-info"><?= ucfirst($userData['role_name']) ?></span>
                
                <!-- Statistics Box -->
                <div class="stats-box mt-3 p-3 bg-light rounded">
                    <div class="row text-center">
                        <div class="col-6">
                            <h6 class="mb-1">Total Hours</h6>
                            <h4 class="text-primary"><?= number_format($totalHours, 1) ?></h4>
                        </div>
                        <div class="col-6">
                            <h6 class="mb-1">Completed Sessions</h6>
                            <h4 class="text-primary"><?= $totalSessions ?></h4>
                        </div>
                    </div>
                </div>
                
                <?php if ($currentSession): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <h6>Current Session</h6>
                    <p class="mb-1">
                        <small><?= date('g:i A', strtotime($currentSession['time_in'])) ?> - <?= date('g:i A', strtotime($currentSession['time_out'])) ?></small>
                    </p>
                    <div class="progress" style="height: 5px;">
                        <?php 
                        $totalMinutes = $currentSession['duration_minutes'];
                        $elapsed = (time() - strtotime($currentSession['time_in'])) / 60;
                        $percent = min(100, max(0, ($elapsed / $totalMinutes) * 100));
                        ?>
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                            role="progressbar" style="width: <?= $percent ?>%" 
                            aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card mt-4">
            <div class="card-body text-center">
                <a href="user_booking.php" class="btn btn-primary w-100 mb-2">
                    <i class="fas fa-plus me-2"></i>New Booking
                </a>
                <a href="profile.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-user-edit me-2"></i>Edit Profile
                </a>
            </div>
        </div>
    </div>
                
                <!-- Sessions Column -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <ul class="nav nav-pills card-header-pills">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" id="all-tab">All</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" id="active-tab">Active</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" id="upcoming-tab">Upcoming</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" id="completed-tab">Completed</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" id="cancelled-tab">Cancelled</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" id="pending-tab">Pending</a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="session-list">
                                <?php if (empty($allSessions)): ?>
                                    <div class="alert alert-info">No sessions or bookings found.</div>
                                <?php else: ?>
                                    <?php foreach ($allSessions as $session): 
    $isBooking = $session['type'] === 'booking';
    $isCancelled = $isBooking && isset($session['status']) && $session['status'] === 'cancelled';
    $isPending = $isBooking && isset($session['status']) && $session['status'] === 'pending';
    $isConfirmed = $isBooking && isset($session['status']) && $session['status'] === 'confirmed';
    $isCompleted = $isBooking && isset($session['status']) && $session['status'] === 'completed';
    $isActive = $isConfirmed && strtotime($session['time_in']) <= time() && strtotime($session['time_out']) > time();
    $isUpcoming = $isConfirmed && strtotime($session['time_in']) > time();
?>
                                    <div class="card session-card mb-3 
                                        <?= $isActive ? 'active-session' : '' ?>
                                        <?= $isUpcoming && $isBooking ? 'pending-booking' : '' ?>
                                        <?= $isCompleted ? 'completed-session' : '' ?>
                                        <?= $isCancelled ? 'border-danger bg-light text-muted' : '' ?>"
                                        data-status="<?= 
    $isCancelled ? 'cancelled' : (
        $isActive ? 'active' : (
            $isUpcoming ? 'upcoming' : (
                $isPending ? 'pending' : (
                    $isCompleted ? 'completed' : 'other'
                )
            )
        )
    )
?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h5 class="card-title mb-1">
                                                        <?= ucfirst($session['room_type']) ?>
                                                        <span class="badge bg-<?= 
                                                            $isCancelled ? 'danger' : (
                                                                $isActive ? 'success' : 
                                                                ($isUpcoming ? 'warning' : (
                                                                    $isPending ? 'warning text-dark' : (
                                                                        $isCompleted ? 'secondary' : 'light'
                                                                    )
                                                                ))
                                                            )
                                                        ?> ms-2">
                                                            <?= $isBooking ? 'Booking' : 'Session' ?>
                                                        </span>
                                                        <?php if ($isBooking && !$isCancelled): ?>
                                                            <?php if (isset($session['status']) && $session['status'] === 'confirmed'): ?>
                                                                <span class="badge bg-success ms-1"><i class="fa fa-check-circle me-1"></i>Confirmed</span>
                                                            <?php elseif (isset($session['status']) && $session['status'] === 'pending'): ?>
                                                                <span class="badge bg-warning text-dark ms-1"><i class="fa fa-hourglass-half me-1"></i>Pending Approval</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <p class="card-text mb-1">
                                                        <i class="far fa-calendar-alt me-1"></i>
                                                        <?= date('M j, Y', strtotime($session['time_in'])) ?>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <i class="far fa-clock me-1"></i>
                                                        <?= date('g:i A', strtotime($session['time_in'])) ?> - 
                                                        <?= date('g:i A', strtotime($session['time_out'])) ?>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <i class="fas fa-stopwatch me-1"></i>
                                                        <?= round($session['duration']/60, 1) ?> hours
                                                    </p>
                                                    <?php if ($isBooking && $session['updated_at']): ?>
                                                        <p class="card-text mb-1">
                                                            <i class="fas fa-pencil-alt me-1"></i>
                                                            Modified: <?= date('M j, Y g:i A', strtotime($session['updated_at'])) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-end">
                                                    <p class="mb-1">
                                                        <strong>₱<?= number_format($session['amount_paid'], 2) ?></strong>
                                                    </p>
                                                    <p class="mb-0">
                                                        <span class="badge bg-<?= 
                                                            $isCancelled ? 'danger' : (
                                                                $isActive ? 'success' : 
                                                                ($isUpcoming ? 'warning' : 'secondary')
                                                            )
                                                        ?>">
                                                            <?= $isCancelled ? 'Cancelled' : (
                                                                $isActive ? 'Active' : (
                                                                    $isUpcoming ? 'Upcoming' : (
                                                                        $isPending ? 'Pending Approval' : (
                                                                            $isCompleted ? 'Completed' : 'Other'
                                                                        )
                                                                    )
                                                                )
                                                            ) ?>
                                                        </span>
                                                    </p>
                                                    <?php if ($isBooking): ?>
                                                        <div class="mt-2">
                                                            <?php if ($isUpcoming && !$isCancelled): ?>
                                                                <a href="cancel_booking.php?id=<?= $session['id'] ?>" 
                                                                class="btn btn-sm btn-danger"
                                                                onclick="return confirm('Are you sure you want to cancel this booking?')">
                                                                    <i class="fas fa-times-circle me-1"></i> Cancel
                                                                </a>
                                                            <?php endif; ?>
                                                            <a href="delete_booking.php?id=<?= $session['id'] ?>" 
                                                                class="btn btn-sm btn-outline-danger"
                                                                onclick="return confirm('Are you sure you want to delete this booking record?')">
                                                                <i class="fas fa-trash-alt me-1"></i> Delete
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($notifications)): ?>
    <div class="alert alert-info shadow-sm" id="notif-alert">
        <?php foreach ($notifications as $notif): ?>
            <div><?= htmlspecialchars($notif['message']) ?> <small class="text-muted">(<?= date('M j, Y H:i', strtotime($notif['created_at'])) ?>)</small></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // Tab filtering functionality
            document.querySelectorAll('.nav-pills .nav-link').forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Update active tab
                    document.querySelectorAll('.nav-pills .nav-link').forEach(t => {
                        t.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // Filter sessions
                    const status = this.id.replace('-tab', '');
                    document.querySelectorAll('.session-card').forEach(card => {
                        if (status === 'all') {
                            card.style.display = 'block';
                        } else {
                            card.style.display = card.dataset.status === status ? 'block' : 'none';
                        }
                    });
                });
            });
            
            // Auto-update progress bar for active session
            <?php if ($currentSession): ?>
            function updateProgressBar() {
                const progressBar = document.querySelector('.current-session-card .progress-bar');
                if (!progressBar) return;
                
                const startTime = new Date("<?= $currentSession['time_in'] ?>").getTime();
                const endTime = new Date("<?= $currentSession['time_out'] ?>").getTime();
                const now = new Date().getTime();
                
                if (now >= endTime) {
                    progressBar.style.width = '100%';
                    return;
                }
                
                const totalDuration = endTime - startTime;
                const elapsed = now - startTime;
                const percent = Math.min(100, (elapsed / totalDuration) * 100);
                
                progressBar.style.width = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
            }
            
            updateProgressBar();
            setInterval(updateProgressBar, 60000);
            <?php endif; ?>
        </script>
        <script>
setTimeout(function() {
    var alert = document.getElementById('notif-alert');
    if(alert) {
        alert.classList.add('fade');
        setTimeout(function() {
            alert.style.display = 'none';
        }, 500);
    }
}, 3000);
</script>
    </body>
    </html>