<?php
// Use "local" to approve documents without uploading to OneDrive.
// Use "onedrive" when Azure/Graph permissions are ready.
$REPOSITORY_MODE = "local";

// Set this in your local environment.
// Azure Portal > App registrations > Certificates & secrets > Client secrets > Value
$M365_CLIENT_SECRET = getenv("M365_CLIENT_SECRET") ?: "";
