<?php
script('social', 'social');
style('social', 'style');
?>
<span id="postData" data-server="<?php p(json_encode($_['item']));?>"></span>
<span id="serverData" data-server="<?php p(json_encode($_['serverData']));?>"></span>
<div id="vue-content"></div>
