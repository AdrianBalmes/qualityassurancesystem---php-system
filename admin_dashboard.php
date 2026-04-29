<?php
session_start();
include("db.php");
include("get_token.php");

if(!isset($_SESSION['username']) || $_SESSION['role'] != "admin"){
    header("Location: index.php");
    exit();
}

/* APPROVE / REJECT */

if(isset($_GET['approve'])){
    $id = intval($_GET['approve']);
    
    // Get document details
    $doc = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM documents WHERE id=$id"));
    
    if($doc){
        try {
            $accessToken = getMicrosoftAccessToken();
        } catch (Exception $e) {
            echo "<script>alert('Failed to get M365 access token: " . addslashes($e->getMessage()) . "');</script>";
            $accessToken = 'eyJ0eXAiOiJKV1QiLCJub25jZSI6IjVpRVNIZjF4Vng5RWpQRFduOURNRW83aDhoZ3pNS0NpVlNIOTlaVml0NnciLCJhbGciOiJSUzI1NiIsIng1dCI6IlUxc1g4WUZIUzdaNlZsN1ZITEl6VGVqYnZqMCIsImtpZCI6IlUxc1g4WUZIUzdaNlZsN1ZITEl6VGVqYnZqMCJ9.eyJhdWQiOiJodHRwczovL2dyYXBoLm1pY3Jvc29mdC5jb20iLCJpc3MiOiJodHRwczovL3N0cy53aW5kb3dzLm5ldC9mNzEwY2I2Yi05ZjI3LTRiZTktYjY4YS04YmU5YmEzZTI2NTcvIiwiaWF0IjoxNzc2MjE0NjYzLCJuYmYiOjE3NzYyMTQ2NjMsImV4cCI6MTc3NjIxODU2MywiYWlvIjoiQVNRQTIvOGJBQUFBdEpFaXZmNzN4ekFNMHhFV2J1dEczeVZodkpZUVBlNHlBSlNVTER3aHBpND0iLCJhcHBfZGlzcGxheW5hbWUiOiJRQSBSZXBvc2l0b3J5IFN5c3RlbSIsImFwcGlkIjoiYjhmODVhYjctM2ZiZi00ZGM2LTk5ZGYtMjU2ZjhjZjAwZmE3IiwiYXBwaWRhY3IiOiIxIiwiaWRwIjoiaHR0cHM6Ly9zdHMud2luZG93cy5uZXQvZjcxMGNiNmItOWYyNy00YmU5LWI2OGEtOGJlOWJhM2UyNjU3LyIsImlkdHlwIjoiYXBwIiwib2lkIjoiZTFiMGNjZDYtMTBmOS00OGIwLWEyNTItZGQ4YWE5OTAxNThhIiwicmgiOiIxLkFYSUFhOHNROXllZjZVdTJpb3ZwdWo0bVZ3TUFBQUFBQUFBQXdBQUFBQUFBQUFBQUFBQnlBQS4iLCJzdWIiOiJlMWIwY2NkNi0xMGY5LTQ4YjAtYTI1Mi1kZDhhYTk5MDE1OGEiLCJ0ZW5hbnRfcmVnaW9uX3Njb3BlIjoiQVMiLCJ0aWQiOiJmNzEwY2I2Yi05ZjI3LTRiZTktYjY4YS04YmU5YmEzZTI2NTciLCJ1dGkiOiI2b3drSUsyN2MwcTl3VDlkaGNFREFBIiwidmVyIjoiMS4wIiwid2lkcyI6WyIwOTk3YTFkMC0wZDFkLTRhY2ItYjQwOC1kNWNhNzMxMjFlOTAiXSwieG1zX2FjZCI6MTc3NDMxMzgzMiwieG1zX2FjdF9mY3QiOiIzIDkiLCJ4bXNfZnRkIjoiaUZEcjM2eXlNbTBYdnc4Rzl6cDRyaVl6cG5LMUZ0M3h1YlVFZGJyLTlYOEJhbUZ3WVc1bFlYTjBMV1J6YlhNIiwieG1zX2lkcmVsIjoiMiA3IiwieG1zX3BmdGV4cCI6MTc3NjMwNDk2MywieG1zX3JkIjoiMC40MkxsWUJKaUxCSVM0V0FYRXRoLXJYZmxCdTZiVGowekRhOHN2YzdQS0NUQ3dTa2s0REQzM0s2dFdWZWNOODY5NUJpNHBXNktrQWdIaDVBQU13TUVISURTUWlJYzNFSUMwbWRUa2liWVp2NTg5X0tyU21Lckh4TUEiLCJ4bXNfc3ViX2ZjdCI6IjkgMyIsInhtc190Y2R0IjoxMzcwODg4OTU3LCJ4bXNfdG50X2ZjdCI6IjE2IDMifQ.GtbLF2dviZfqnpsB_LPP0TcKvsncKs4u42SHuC3C-QO-tb-ZYKSqQclh5VI99X7_UwMkt__zUKHGQsJXOxfPXtAE77EI-ufzAaAf4yuNuyVQVex97r9Y928zcZD555DgVVP2I5cgwZIrQ5lSsYGYLt2IHZFXAGx0aWaEf5X3QP8KGk80WjUs3mQq1fGh9Drg0R0eLviE4XpEqOHRLheQk4DIHVMxwQZ5hEg9EAmk15o4fPnNoH0plYKIqRsqO4Rb1oO7w3aJHrxbPXLtZrQ-7GbZs-mid9b_dXP1mnyseBWfTKSsSJxTcCOxFETC3RBVeT_16PoqgxQ6RZtufLw5xw';
        }

        if ($accessToken) {
            $pathSegments = [
                'QA Repository System',
                $doc['office'],
                $doc['file_name']
            ];
            $encodedPath = implode('/', array_map('rawurlencode', $pathSegments));
            $uploadUrl = "https://graph.microsoft.com/v1.0/users/ao.balmes-student@sbcbatangas.edu.ph/drive/root:/{$encodedPath}:/content";

            $file_path = "uploads/" . $doc['file_name'];
            if (!file_exists($file_path) || !is_readable($file_path)) {
                echo "<script>alert('Local file not found or unreadable: " . addslashes($file_path) . "');</script>";
            } else {
                $fp = fopen($file_path,'r');
                $fileSize = filesize($file_path);

                $headers = [
                    "Authorization: Bearer $accessToken",
                    "Content-Type: application/octet-stream"
                ];

                $ch = curl_init($uploadUrl);
                curl_setopt($ch, CURLOPT_UPLOAD, true);
                curl_setopt($ch, CURLOPT_INFILE, $fp);
                curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                fclose($fp);

                if ($curlError) {
                    echo "<script>alert('cURL error during upload: " . addslashes($curlError) . "');</script>";
                } elseif ($httpCode >= 200 && $httpCode < 300) {
                    mysqli_query($conn,"UPDATE documents SET approval_status='Approved' WHERE id=$id");
                    echo "<script>alert('Document Approved and Uploaded to M365!');</script>";
                } else {
                    $errorResponse = json_decode($response, true);
                    $errorMsg = isset($errorResponse['error']['message']) ? $errorResponse['error']['message'] : $response;
                    echo "<script>alert('M365 Upload Failed! Document not approved. Error: $httpCode - " . addslashes($errorMsg) . "');</script>";
                }
            }
        }
    }
}

if(isset($_GET['reject'])){
    $id = intval($_GET['reject']);
    mysqli_query($conn,"UPDATE documents SET approval_status='Rejected' WHERE id=$id");
    echo "<script>alert('Document Rejected!');</script>";
}

/* SUMMARY COUNTS */

$implemented = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Implemented'"))['total'];
$partial = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Partially'"))['total'];
$notimpl = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Not Implemented'"))['total'];
$totaldocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents"))['total'];

?>

<!DOCTYPE html>
<html>
<head>

<title>SBC QA Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

body{
background:#f5f6fa;
}

.navbar{
background:linear-gradient(to right,#3a7bd5,#3a6073);
}

.card{
border:none;
border-radius:12px;
box-shadow:0 2px 10px rgba(0,0,0,0.08);
}

.stat-card{
padding:15px;
color:white;
}

.green{background:#2ecc71;}
.yellow{background:#f1c40f;}
.red{background:#e74c3c;}
.gray{background:#7f8c8d;}

.office-box{
text-align:center;
padding:15px;
border-radius:10px;
background:white;
box-shadow:0 2px 8px rgba(0,0,0,0.1);
}

.office-box:hover{
transform:scale(1.05);
transition:0.2s;
}

</style>

</head>

<body>

<!-- NAVBAR -->

<nav class="navbar navbar-expand-lg navbar-dark px-4">
<a class="navbar-brand text-white fw-bold">
📄 SBC Quality Assurance Electronic Documentation Dashboard
</a>

<div class="ms-auto">
<a href="logout.php" class="btn btn-light btn-sm">Logout</a>
</div>
</nav>

<div class="container mt-4">

<!-- STATISTICS -->

<div class="row mb-4">

<div class="col-md-3">
<div class="card stat-card green">
Documents Implemented
<h3><?php echo $implemented ?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card yellow">
Partially Implemented
<h3><?php echo $partial ?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card red">
Not Implemented
<h3><?php echo $notimpl ?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card gray">
Total Documents
<h3><?php echo $totaldocs ?></h3>
</div>
</div>

</div>

<!-- CHART + NOTIFICATIONS -->

<div class="row">

<div class="col-md-8">

<div class="card p-3">
<h5>Implementation Status</h5>

<canvas id="chart"></canvas>

</div>

</div>

<div class="col-md-4">

<div class="card p-3">

<h5>Notifications</h5>

<ul class="list-group list-group-flush">

<li class="list-group-item">
New Document Uploaded
</li>

<li class="list-group-item">
COE Submission Overdue
</li>

<li class="list-group-item">
Policy Update Pending Review
</li>

<li class="list-group-item">
Feedback Completed
</li>

</ul>

</div>

</div>

</div>

<br>

<!-- TASK TABLE -->

<div class="card p-3">

<h5>Task Monitoring</h5>

<table class="table">

<tr>
<th>Task</th>
<th>Office</th>
<th>Due Date</th>
<th>Status</th>
</tr>

<?php

$tasks = mysqli_query($conn,"SELECT * FROM tasks");

while($row = mysqli_fetch_assoc($tasks)){

echo "<tr>

<td>{$row['task_name']}</td>
<td>{$row['office']}</td>
<td>{$row['due_date']}</td>
<td>{$row['status']}</td>

</tr>";

}

?>

</table>

</div>

<br>

<!-- DOCUMENT LIST -->

<div class="card p-3">

<h5>Uploaded Documents</h5>

<table class="table table-bordered">

<tr>
<th>Office</th>
<th>Title</th>
<th>Status</th>
<th>Approval</th>
<th>File</th>
<th>Action</th>
</tr>

<?php

$result = mysqli_query($conn,"SELECT * FROM documents ORDER BY id DESC");

while($row = mysqli_fetch_assoc($result)){

if($row['approval_status']=="Approved"){
$badge="<span class='badge bg-success'>Approved</span>";
}
elseif($row['approval_status']=="Rejected"){
$badge="<span class='badge bg-danger'>Rejected</span>";
}
else{
$badge="<span class='badge bg-warning text-dark'>Pending</span>";
}

echo "

<tr>

<td>{$row['office']}</td>
<td>{$row['title']}</td>
<td>{$row['status']}</td>
<td>$badge</td>

<td>
<a href='uploads/{$row['file_name']}' target='_blank'>View</a>
</td>

<td>

<a href='?approve={$row['id']}' class='btn btn-success btn-sm'>Approve</a>

<a href='?reject={$row['id']}' class='btn btn-danger btn-sm'>Reject</a>

</td>

</tr>

";

}

?>

</table>

</div>

<br>

<!-- OFFICES -->

<h5>Office Document Submissions</h5>

<div class="row">

<div class="col-md-2">
<div class="office-box">Faculty</div>
</div>

<div class="col-md-2">
<div class="office-box">CSSAO</div>
</div>

<div class="col-md-2">
<div class="office-box">IBED</div>
</div>

<div class="col-md-2">
<div class="office-box">SHS</div>
</div>

<div class="col-md-2">
<div class="office-box">Registrar</div>
</div>

<div class="col-md-2">
<div class="office-box">Finance</div>
</div>

</div>

</div>

<script>

const ctx = document.getElementById('chart');

new Chart(ctx, {

type:'bar',

data:{

labels:['Implemented','Partially','Not Implemented'],

datasets:[{

label:'Documents',

data:[<?php echo $implemented ?>,<?php echo $partial ?>,<?php echo $notimpl ?>]

}]

}

});

</script>

</body>
</html>