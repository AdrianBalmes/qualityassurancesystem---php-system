<?php
session_start();
include("db.php");

if(!isset($_SESSION['username'])){
    header("Location: index.php");
    exit();
}

$office = $_SESSION['office'];

if(isset($_POST['upload'])){

    $title  = $_POST['title'];
    $status = $_POST['status'];

    $file_name = $_FILES['file']['name'];
    $tmp_name  = $_FILES['file']['tmp_name'];

    $upload_path = "uploads/" . $file_name;

    if(move_uploaded_file($tmp_name, $upload_path)){
        $stmt = $conn->prepare("INSERT INTO documents (office,title,status,file_name,approval_status) VALUES (?,?,?,?,?)");
        $approval_status = 'Pending';
        $stmt->bind_param("sssss",$office,$title,$status,$file_name,$approval_status);
        $stmt->execute();
        echo "<script>alert('Document Submitted Successfully! Waiting for Admin Approval.'); window.location='office_dashboard.php';</script>";
    }else{
        echo "<script>alert('Upload Failed!');</script>";
    }
}

$implemented = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Implemented' AND office='$office'"))['total'];
$partial = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Partially' AND office='$office'"))['total'];
$notimpl = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Not Implemented' AND office='$office'"))['total'];
$totaldocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$office'"))['total'];
?>

<!DOCTYPE html>
<html>
<head>
<title><?php echo $office; ?> Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background: #f5f6fa;
}

.navbar {
    background: linear-gradient(to right, #3a7bd5, #3a6073);
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}

.stat-card {
    padding: 15px;
    color: white;
    border-radius: 10px;
}

.stat-card.green { background: #2ecc71; }
.stat-card.yellow { background: #f1c40f; color: #333; }
.stat-card.red { background: #e74c3c; }
.stat-card.gray { background: #7f8c8d; }
</style>
</head>
<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark px-4">
    <a class="navbar-brand text-white fw-bold">
        📄 SBC Quality Assurance - Electronic Documentation
    </a>
    <div class="ms-auto">
        <span class="text-white me-3">Welcome, <?php echo $_SESSION['username']; ?></span>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4">

<h3><?php echo $office; ?> Dashboard</h3>

<!-- SUMMARY CARDS -->
<div class="row mb-4">

<div class="col-md-3">
    <div class="card stat-card green">
        <div class="card-body">
            <h6>Implemented</h6>
            <h3><?php echo $implemented; ?></h3>
        </div>
    </div>
</div>

<div class="col-md-3">
    <div class="card stat-card yellow">
        <div class="card-body">
            <h6>Partially</h6>
            <h3><?php echo $partial; ?></h3>
        </div>
    </div>
</div>

<div class="col-md-3">
    <div class="card stat-card red">
        <div class="card-body">
            <h6>Not Implemented</h6>
            <h3><?php echo $notimpl; ?></h3>
        </div>
    </div>
</div>

<div class="col-md-3">
    <div class="card stat-card gray">
        <div class="card-body">
            <h6>Total Documents</h6>
            <h3><?php echo $totaldocs; ?></h3>
        </div>
    </div>
</div>

</div>

<!-- UPLOAD FORM -->
<div class="card mb-4">
    <div class="card-body">
        <h5>Submit Document for Approval</h5>
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-2">
                <input type="text" name="title" class="form-control" placeholder="Document Title" required>
            </div>

            <div class="mb-2">
                <select name="status" class="form-select" required>
                    <option value="">Select Implementation Status</option>
                    <option value="Implemented">Implemented</option>
                    <option value="Partially">Partially Implemented</option>
                    <option value="Not Implemented">Not Implemented</option>
                </select>
            </div>

            <div class="mb-2">
                <input type="file" name="file" class="form-control" required>
            </div>

            <button type="submit" name="upload" class="btn btn-primary">Submit for Approval</button>
        </form>
    </div>
</div>

<!-- DOCUMENTS TABLE -->
<div class="card mb-4">
    <div class="card-body">
        <h5>Your Uploaded Documents</h5>
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Approval Status</th>
                        <th>Uploaded Date</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = mysqli_query($conn,"SELECT * FROM documents WHERE office='$office' ORDER BY id DESC");

                    if(mysqli_num_rows($result) > 0){
                        while($row = mysqli_fetch_assoc($result)){
                            if($row['approval_status'] == "Approved"){
                                $badge = "<span class='badge bg-success'>Approved</span>";
                            } elseif($row['approval_status'] == "Rejected"){
                                $badge = "<span class='badge bg-danger'>Rejected</span>";
                            } else {
                                $badge = "<span class='badge bg-warning text-dark'>Pending</span>";
                            }

                            $uploadedDate = isset($row['created_at']) ? $row['created_at'] : 'N/A';
                            echo "
                            <tr>
                                <td>{$row['title']}</td>
                                <td>{$row['status']}</td>
                                <td>$badge</td>
                                <td>{$uploadedDate}</td>
                                <td><a href='uploads/{$row['file_name']}' target='_blank' class='btn btn-sm btn-info'>View</a></td>
                            </tr>
                            ";
                        }
                    } else {
                        echo "<tr><td colspan='5' class='text-center'>No documents uploaded yet</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<a href="logout.php" class="btn btn-dark">Logout</a>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>