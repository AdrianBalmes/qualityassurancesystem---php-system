<?php
// PHP and MySQL must agree on the clock: the code stores times made with PHP's
// date() and compares them with MySQL's NOW(). XAMPP's php.ini defaults to
// Europe/Berlin, six hours off local time, which made every password-reset
// code expire the moment it was issued.
date_default_timezone_set('Asia/Manila');

$conn = mysqli_connect("localhost", "root", "", "ems_db");

if(!$conn){
    die("Connection Failed: " . mysqli_connect_error());
}

mysqli_query($conn, "SET time_zone = '" . date('P') . "'");
