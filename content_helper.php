<?php

function sc_load($conn){
    $map = [];
    $result = mysqli_query($conn, "SELECT content_key, content_value FROM site_content");
    if($result){
        while($row = mysqli_fetch_assoc($result)){
            $map[$row['content_key']] = $row['content_value'];
        }
    }
    return $map;
}

function sc_val(array $map, $key, $default){
    return isset($map[$key]) && $map[$key] !== '' ? $map[$key] : $default;
}

function sc_span(array $map, $key, $default, $tag = 'span', $class = ''){
    $value = sc_val($map, $key, $default);
    $safeValue = htmlspecialchars($value, ENT_QUOTES);
    $safeKey = htmlspecialchars($key, ENT_QUOTES);
    $safeClass = htmlspecialchars(trim('editable-text ' . $class), ENT_QUOTES);
    echo "<{$tag} class=\"{$safeClass}\" data-key=\"{$safeKey}\">{$safeValue}</{$tag}>";
}

function render_edit_toggle(){
    if(!isset($_SESSION['admin_username'])){
        return;
    }
    ?>
    <style>
    .edit-toggle-btn{position:fixed;right:22px;bottom:22px;z-index:2500;background:#316fc4;color:#fff;border:0;border-radius:999px;padding:12px 18px;font-weight:800;box-shadow:0 10px 26px rgba(44,74,119,.35);display:inline-flex;align-items:center;gap:8px;font-size:14px;max-width:calc(100vw - 32px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .edit-toggle-btn.active{background:#e0663f}
    .editable-text.editing-active{outline:2px dashed #316fc4;background:#eef4ff;border-radius:3px;padding:1px 4px;cursor:text}
    @media(max-width:480px){.edit-toggle-btn{right:12px;bottom:12px;padding:10px 14px;font-size:13px}}
    </style>
    <button type="button" id="editModeToggle" class="edit-toggle-btn"><i class="bi bi-pencil-square"></i> Edit Page Text</button>
    <script>
    (function(){
        var btn = document.getElementById('editModeToggle');
        if(!btn){ return; }
        var active = false;

        function persist(el){
            var key = el.getAttribute('data-key');
            var value = el.innerText.replace(/\n+$/,'');
            fetch('save_content.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'content_key=' + encodeURIComponent(key) + '&content_value=' + encodeURIComponent(value)
            });
        }

        btn.addEventListener('click', function(){
            active = !active;
            document.querySelectorAll('.editable-text').forEach(function(el){
                el.setAttribute('contenteditable', active ? 'true' : 'false');
                el.classList.toggle('editing-active', active);
            });
            btn.innerHTML = active ? '<i class="bi bi-check2-circle"></i> Done Editing' : '<i class="bi bi-pencil-square"></i> Edit Page Text';
            btn.classList.toggle('active', active);
        });

        document.querySelectorAll('.editable-text').forEach(function(el){
            el.addEventListener('blur', function(){
                if(active){ persist(el); }
            });
        });
    })();
    </script>
    <?php
}
