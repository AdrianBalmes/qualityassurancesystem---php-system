<?php

function audit_type_for_office($office){
    $externalOffices = ['BED', 'College Department'];
    return in_array(trim($office), $externalOffices, true) ? 'External' : 'Internal';
}
