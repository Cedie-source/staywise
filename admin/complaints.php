<?php
ob_start();
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
require_once '../includes/email_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php"); exit();
}

// Ensure table exists
$conn->query("SET foreign_key_checks=0");
$conn->query("CREATE TABLE IF NOT EXISTS complaint_replies (
    reply_id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin','tenant') NOT NULL DEFAULT 'admin',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("SET foreign_key_checks=1");

// AJAX: load replies
if (isset($_GET['ajax_replies'])) {
    ob_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['complaint_id'] ?? 0);
    $rows = [];
    $st = $conn->prepare("SELECT cr.reply_id, cr.complaint_id, cr.role, cr.message, cr.created_at, COALESCE(u.full_name, u.username, 'User') AS full_name FROM complaint_replies cr LEFT JOIN users u ON cr.user_id=u.id WHERE cr.complaint_id=? ORDER BY cr.created_at ASC");
    if ($st) { $st->bind_param("i",$cid); $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()) $rows[]=$r; $st->close(); }
    echo json_encode(['success'=>true,'replies'=>$rows]);
    exit();
}

// AJAX: send reply
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_send_reply'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token']??'')) { echo json_encode(['success'=>false,'error'=>'Invalid token']); exit(); }
    $cid = intval($_POST['complaint_id']??0);
    $msg = trim($_POST['reply_message']??'');
    $uid = intval($_SESSION['user_id']);
    if ($cid<=0||empty($msg)) { echo json_encode(['success'=>false,'error'=>'Missing data']); exit(); }
    $ins = $conn->prepare("INSERT INTO complaint_replies (complaint_id,user_id,role,message) VALUES (?,?,'admin',?)");
    if (!$ins) { echo json_encode(['success'=>false,'error'=>$conn->error]); exit(); }
    $ins->bind_param("iis",$cid,$uid,$msg);
    $ok=$ins->execute(); $rid=$ins->insert_id; $err=$ins->error; $ins->close();
    if (!$ok||!$rid) { echo json_encode(['success'=>false,'error'=>$err]); exit(); }
    $name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
    $reply = ['reply_id'=>$rid,'complaint_id'=>$cid,'role'=>'admin','message'=>$msg,'created_at'=>date('Y-m-d H:i:s'),'full_name'=>$name];
    try { logAdminAction($conn,$uid,'reply_complaint',"Replied to #$cid"); } catch(Throwable $e){}

    // Notify tenant in-app + email
    try {
        $tInfo = $conn->prepare("SELECT t.user_id, t.name, t.email, c.title FROM complaints c JOIN tenants t ON c.tenant_id=t.tenant_id WHERE c.complaint_id=?");
        $tInfo->bind_param("i",$cid); $tInfo->execute();
        $tRow = $tInfo->get_result()->fetch_assoc(); $tInfo->close();
        if ($tRow) {
            $nTitle = "💬 Admin replied to your complaint";
            $nMsg   = "Re: " . mb_substr($tRow['title'],0,60) . " — " . mb_substr($msg,0,80) . (mb_strlen($msg)>80?'…':'');
            $nIns = $conn->prepare("INSERT INTO ai_notifications (user_id,type,title,message,priority,action_url,related_id) VALUES (?,'advisory',?,?,'medium','tenant/complaints.php',?)");
            $nIns->bind_param("issi",$tRow['user_id'],$nTitle,$nMsg,$cid);
            $nIns->execute(); $nIns->close();
            // Email notification removed - in-app only
        }
    } catch(Throwable $e){}

    echo json_encode(['success'=>true,'reply'=>$reply]);
    exit();
}

// Update complaint
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_complaint'])) {
    if (!verify_csrf_token($_POST['csrf_token']??'')) { http_response_code(400); die('Invalid token.'); }
    $cid=intval($_POST['complaint_id']??0); $status=$_POST['status']??''; $resp=trim($_POST['admin_response']??'');
    $upd=$conn->prepare("UPDATE complaints SET status=?,admin_response=? WHERE complaint_id=?");
    if ($upd) { $upd->bind_param("ssi",$status,$resp,$cid); $upd->execute(); $upd->close(); }
    try { logAdminAction($conn,$_SESSION['user_id'],'update_complaint',"Updated #$cid to $status"); } catch(Throwable $e){}
    // Notify tenant of status change
    try {
        $tInfo2 = $conn->prepare("SELECT t.user_id, t.name, t.email, c.title FROM complaints c JOIN tenants t ON c.tenant_id=t.tenant_id WHERE c.complaint_id=?");
        $tInfo2->bind_param("i",$cid); $tInfo2->execute();
        $tRow2 = $tInfo2->get_result()->fetch_assoc(); $tInfo2->close();
        if ($tRow2) {
            $sLabels = ['pending'=>'Pending','ongoing'=>'In Progress 🔧','resolved'=>'Resolved ✅'];
            $sLabel  = $sLabels[$status] ?? ucfirst($status);
            $nTitle2 = "Complaint Update: $sLabel";
            $nMsg2   = "\"" . mb_substr($tRow2['title'],0,60) . "\" marked as $sLabel." . (!empty($resp) ? " Admin: ".mb_substr($resp,0,80) : '');
            $nIns2 = $conn->prepare("INSERT INTO ai_notifications (user_id,type,title,message,priority,action_url,related_id) VALUES (?,'advisory',?,?,'medium','tenant/complaints.php',?)");
            $nIns2->bind_param("issi",$tRow2['user_id'],$nTitle2,$nMsg2,$cid);
            $nIns2->execute(); $nIns2->close();
            // Email notification removed - in-app only
        }
    } catch(Throwable $e){}
    header("Location: complaints.php?updated=1"); exit();
}

if (isset($_GET['updated'])) $success="Complaint updated!";

$hasUrgent    = db_column_exists($conn,'complaints','urgent');
$hasAdminResp = db_column_exists($conn,'complaints','admin_response');
$hasCompDate  = db_column_exists($conn,'complaints','complaint_date');

$extra = '';
$extra .= $hasUrgent    ? ',c.urgent'         : ',0 AS urgent';
$extra .= $hasAdminResp ? ',c.admin_response'  : ",' ' AS admin_response";
$extra .= $hasCompDate  ? ',c.complaint_date'  : ',c.created_at AS complaint_date';

$result = $conn->query("SELECT c.complaint_id,c.title,c.description,c.status,c.created_at,t.name AS tenant_name,t.unit_number$extra FROM complaints c JOIN tenants t ON c.tenant_id=t.tenant_id ORDER BY c.created_at DESC");
if (!$result) die("DB error: ".$conn->error);

$replyCounts=[];
$rc=$conn->query("SELECT complaint_id,COUNT(*) as cnt FROM complaint_replies GROUP BY complaint_id");
if($rc) while($r=$rc->fetch_assoc()) $replyCounts[$r['complaint_id']]=(int)$r['cnt'];

$page_title="Manage Complaints";
include '../includes/header.php';
?>

<style>
.complaint-thread-box{max-height:380px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;padding:.5rem 0;scroll-behavior:smooth;}
.chat-bubble{display:flex;flex-direction:column;max-width:78%;padding:.55rem .85rem;border-radius:14px;font-size:.875rem;line-height:1.5;word-break:break-word;}
.chat-bubble.admin{align-self:flex-end;background:#1e293b;color:#f1f5f9;border-bottom-right-radius:4px;}
.chat-bubble.tenant{align-self:flex-start;background:#f1f5f9;color:#0f172a;border-bottom-left-radius:4px;}
body.dark-mode .chat-bubble.tenant{background:#2a2a2a;color:#e2e8f0;}
.chat-bubble .bubble-meta{font-size:.7rem;opacity:.6;margin-bottom:.2rem;}
.chat-bubble .bubble-time{font-size:.68rem;opacity:.5;margin-top:.25rem;text-align:right;}
.chat-bubble.tenant .bubble-time{text-align:left;}
.thread-input-row{display:flex;gap:.5rem;margin-top:.75rem;align-items:flex-end;}
.thread-input-row textarea{flex:1;border-radius:12px;padding:.45rem 1rem;resize:none;}
.thread-input-row button{border-radius:12px;padding:.55rem 1.1rem;white-space:nowrap;flex-shrink:0;}
.thread-loading{text-align:center;padding:1.5rem 0;color:#94a3b8;font-size:.85rem;}
.thread-error{color:#ef4444;font-size:.8rem;margin-top:.4rem;display:none;}
</style>

<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title"><i class="fas fa-exclamation-triangle me-2"></i>Tenant Complaints</h2>

    <?php if(isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?=htmlspecialchars($success)?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <input type="text" class="form-control" id="complaintSearch" placeholder="Search by tenant, unit, title, or status...">
    </div>

    <?php if($result->num_rows>0): ?>
    <div class="row" id="complaintsList">
    <?php while($c=$result->fetch_assoc()):
        $cid=$c['complaint_id'];
        $rcnt=$replyCounts[$cid]??0;
        $isUrgent=((int)($c['urgent']??0)===1)||stripos((string)$c['title'],'[URGENT]')===0;
    ?>
        <div class="col-12 mb-3 complaint-card-wrapper">
            <div class="card <?=$isUrgent?'border-danger':''?>">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <?php if($isUrgent):?><span class="badge bg-danger me-2"><i class="fas fa-bolt me-1"></i>Urgent</span><?php endif;?>
                        <strong><?=htmlspecialchars($c['title'])?></strong>
                        <span class="badge status-<?=htmlspecialchars(str_replace(' ','-',strtolower($c['status'])))?> ms-2"><?=ucfirst($c['status'])?></span>
                        <span class="badge bg-info ms-1" id="badge-<?=$cid?>"><i class="fas fa-comments me-1"></i><span id="badgeCount-<?=$cid?>"><?=$rcnt?></span></span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted"><i class="fas fa-user me-1"></i><?=htmlspecialchars($c['tenant_name'])?>
                            <span class="badge bg-primary ms-1"><?=htmlspecialchars($c['unit_number'])?></span>
                        </small>
                        <button class="btn btn-sm btn-dark thread-toggle-btn" data-cid="<?=$cid?>"
                                data-bs-toggle="collapse" data-bs-target="#thread<?=$cid?>">
                            <i class="fas fa-comments me-1"></i>Thread
                        </button>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?=$cid?>">
                            <i class="fas fa-edit me-1"></i>Edit
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="mb-2"><?=nl2br(htmlspecialchars($c['description']))?></p>
                    <small class="text-muted">
                        <i class="fas fa-calendar me-1"></i><?=date('M d, Y',strtotime($c['complaint_date']))?>
                        <span class="ms-3"><i class="fas fa-clock me-1"></i>Submitted: <?=date('M d, Y g:i A',strtotime($c['created_at']))?></span>
                    </small>
                    <?php if(!empty($c['admin_response'])):?>
                    <div class="alert alert-secondary mt-2 mb-0">
                        <strong><i class="fas fa-reply me-1"></i>Admin Response:</strong><br>
                        <?=nl2br(htmlspecialchars($c['admin_response']))?>
                    </div>
                    <?php endif;?>
                </div>
                <div class="collapse" id="thread<?=$cid?>">
                    <div class="card-body border-top pt-3">
                        <h6 class="mb-2"><i class="fas fa-comments me-2"></i>Conversation Thread</h6>
                        <div class="complaint-thread-box" id="threadBox<?=$cid?>">
                            <div class="thread-loading"><i class="fas fa-circle-notch fa-spin me-1"></i>Loading...</div>
                        </div>
                        <div class="thread-input-row">
                            <textarea class="form-control thread-reply-input" id="replyInput<?=$cid?>"
                                      placeholder="Type reply... (Enter = new line, click Send to submit)"
                                      data-cid="<?=$cid?>" rows="2"></textarea>
                            <button class="btn btn-primary thread-send-btn" data-cid="<?=$cid?>">
                                <i class="fas fa-paper-plane me-1"></i>Send
                            </button>
                        </div>
                        <div class="thread-error" id="threadErr<?=$cid?>"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editModal<?=$cid?>" tabindex="-1">
            <div class="modal-dialog"><form method="POST">
                <?=csrf_input()?>
                <input type="hidden" name="complaint_id" value="<?=$cid?>">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Complaint</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="pending"  <?=$c['status']=='pending' ?'selected':''?>>Pending</option>
                                <option value="ongoing"  <?=$c['status']=='ongoing' ?'selected':''?>>Ongoing</option>
                                <option value="resolved" <?=$c['status']=='resolved'?'selected':''?>>Resolved</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Response</label>
                            <textarea class="form-control" name="admin_response" rows="3"><?=htmlspecialchars($c['admin_response']??'')?></textarea>
                            <div class="form-text">Shown on tenant's card. Use Thread for chat.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_complaint" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </form></div>
        </div>
    <?php endwhile;?>
    </div>
    <?php else:?>
        <p class="no-record-message dashboard-title">No complaints found.</p>
    <?php endif;?>
</div>

<script>
(function(){
    var CSRF = <?=json_encode(csrf_token())?>;

    // Search
    var srch=document.getElementById('complaintSearch');
    if(srch) srch.addEventListener('input',function(){
        var q=this.value.toLowerCase();
        document.querySelectorAll('.complaint-card-wrapper').forEach(function(el){
            el.style.display=el.textContent.toLowerCase().includes(q)?'':'none';
        });
    });

    function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

    function makeBubble(r){
        var isAdmin=r.role==='admin';
        var name=r.full_name||(isAdmin?'Admin':'Tenant');
        var dt=new Date(r.created_at.replace(' ','T'));
        var time=isNaN(dt.getTime())?r.created_at:dt.toLocaleString('en-PH',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
        var d=document.createElement('div');
        d.className='chat-bubble '+r.role;
        d.innerHTML='<div class="bubble-meta"><i class="fas '+(isAdmin?'fa-user-shield':'fa-user')+' me-1"></i>'+esc(name)
            +' <span class="badge '+(isAdmin?'bg-light text-dark':'bg-secondary')+' ms-1" style="font-size:.65rem;">'+(isAdmin?'Admin':'Tenant')+'</span></div>'
            +'<div>'+esc(r.message).replace(/\n/g,'<br>')+'</div>'
            +'<div class="bubble-time">'+time+'</div>';
        return d;
    }

    function scrollBot(box){box.scrollTop=box.scrollHeight;}

    function showErr(cid,msg){
        var el=document.getElementById('threadErr'+cid);
        if(el){el.textContent=msg;el.style.display=msg?'block':'none';}
    }

    // Track which threads are loaded
    var threadState={}; // cid -> {loaded: bool, loading: bool}

    function getState(cid){
        if(!threadState[cid]) threadState[cid]={loaded:false,loading:false};
        return threadState[cid];
    }

    function renderReplies(cid, replies){
        var box=document.getElementById('threadBox'+cid);
        box.innerHTML='';
        if(replies&&replies.length>0){
            replies.forEach(function(r){box.appendChild(makeBubble(r));});
        } else {
            box.innerHTML='<p class="text-muted text-center small py-3 mb-0"><em>No messages yet. Start the conversation.</em></p>';
        }
        scrollBot(box);
    }

    function loadReplies(cid, force){
        var state=getState(cid);
        if(state.loading) return;
        if(state.loaded && !force) return;
        state.loading=true;

        var box=document.getElementById('threadBox'+cid);
        if(!state.loaded){
            box.innerHTML='<div class="thread-loading"><i class="fas fa-circle-notch fa-spin me-1"></i>Loading...</div>';
        }

        fetch('complaints.php?ajax_replies=1&complaint_id='+cid,{credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(data){
                renderReplies(cid, data.replies||[]);
                state.loaded=true;
                state.loading=false;
            })
            .catch(function(){
                var box=document.getElementById('threadBox'+cid);
                if(box) box.innerHTML='<p class="text-danger text-center small py-3">Could not load messages.</p>';
                state.loading=false;
            });
    }

    // Thread toggle - load on open only
    document.querySelectorAll('.thread-toggle-btn').forEach(function(btn){
        btn.addEventListener('click',function(){
            var cid=this.dataset.cid;
            var thread=document.getElementById('thread'+cid);
            // Check if currently collapsed (about to open)
            var isCollapsed=thread.classList.contains('show')===false;
            if(isCollapsed){
                // Small delay to let Bootstrap open the collapse first
                setTimeout(function(){loadReplies(cid,false);},100);
            }
        });
    });

    // Send reply
    function send(cid){
        var inp=document.getElementById('replyInput'+cid);
        var btn=document.querySelector('.thread-send-btn[data-cid="'+cid+'"]');
        var msg=inp?inp.value.trim():'';
        if(!msg) return;

        showErr(cid,'');
        inp.disabled=true;
        btn.disabled=true;
        btn.innerHTML='<i class="fas fa-circle-notch fa-spin"></i>';

        var fd=new FormData();
        fd.append('ajax_send_reply','1');
        fd.append('complaint_id',cid);
        fd.append('reply_message',msg);
        fd.append('csrf_token',CSRF);

        fetch('complaints.php',{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){
                var ct=r.headers.get('content-type')||'';
                if(!ct.includes('application/json')){
                    return r.text().then(function(t){throw new Error(t.substring(0,300));});
                }
                return r.json();
            })
            .then(function(data){
                if(data.success && data.reply){
                    var box=document.getElementById('threadBox'+cid);
                    // Remove "no messages" placeholder
                    var empty=box.querySelector('p.text-muted');
                    if(empty) empty.remove();
                    // Append bubble
                    box.appendChild(makeBubble(data.reply));
                    scrollBot(box);
                    inp.value='';
                    // Mark as needing reload next time (so DB version loads on reopen)
                    getState(cid).loaded=false;
                    // Update badge
                    var sp=document.getElementById('badgeCount-'+cid);
                    if(sp) sp.textContent=parseInt(sp.textContent||'0')+1;
                } else {
                    showErr(cid,'Error: '+(data.error||'Unknown'));
                }
            })
            .catch(function(e){showErr(cid,'Failed: '+e.message);})
            .finally(function(){
                inp.disabled=false;
                btn.disabled=false;
                btn.innerHTML='<i class="fas fa-paper-plane me-1"></i>Send';
            });
    }

    document.addEventListener('click',function(e){
        var b=e.target.closest('.thread-send-btn');
        if(b) send(b.dataset.cid);
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
