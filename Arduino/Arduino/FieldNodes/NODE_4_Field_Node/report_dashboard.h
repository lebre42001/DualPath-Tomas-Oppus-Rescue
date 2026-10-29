#pragma once
const char REPORT_GUARD_SCRIPT[] PROGMEM = R"guard(
<script>
(()=>{
  const byId=id=>document.getElementById(id), button=byId('sendButton')||byId('send');
  const message=byId('message'), type=byId('type');
  for(const option of type.options)option.disabled=option.value!=='ROAD_ACCIDENT';
  type.value='ROAD_ACCIDENT';
  const field=document.createElement('label');
  field.textContent='Actual accident location / landmark (required)';
  field.style.display='block';field.style.marginTop='12px';
  const landmark=document.createElement('input');landmark.id='landmark';landmark.maxLength=32;
  landmark.placeholder='E.g. Bogo bridge, near school';landmark.required=true;
  landmark.style.cssText='width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd6cf;border-radius:10px;font-size:16px;margin-top:8px';
  field.append(landmark);message.before(field);
  const indicator=document.createElement('p');indicator.id='reportGuard';indicator.className='small';
  indicator.setAttribute('role','status');indicator.setAttribute('aria-live','polite');button.after(indicator);
  const radioDetails=document.createElement('details');
  radioDetails.style.cssText='margin:14px 0;padding:12px;background:#f4f7f5;border-radius:10px';
  radioDetails.innerHTML='<summary>Radio diagnostics &amp; range test</summary><p class="small">Readings belong to the last authenticated radio packet, not a live signal meter. Green LED means a direct peer was heard within 40 seconds; it does not prove a working server route.</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px" data-radio-diagnostics>Waiting for radio status...</pre>';
  indicator.after(radioDetails);
  const modal=document.createElement('div');modal.className='modal';modal.id='confirmReport';
  modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','confirmReportTitle');
  modal.innerHTML='<div class="modal-box box" style="max-height:85vh;overflow:auto"><h2 id="confirmReportTitle">Confirm emergency</h2><p>Road Accident</p><b>Actual location</b><p id="confirmLocation"></p><b>Description</b><p id="confirmDescription"></p><p class="small">Send accurate details. Your report will be reviewed by the emergency team.</p><button id="confirmSend">Confirm and send</button><button id="confirmBack" style="background:#eef3ef;color:#14251a">Go back</button></div>';
  document.body.append(modal);
  let busy=false,lastStatus={},retryUntil=0,reviewedPayload=null;
  const bytes=value=>new TextEncoder().encode(value).length;
  const valid=()=>{
    if(!landmark.value.trim()||bytes(landmark.value.trim())>32){alert('Enter a short accident location or landmark. Shorten it if it is too long.');landmark.focus();return false}
    if(!message.value.trim()||bytes(message.value.trim())>70){alert('Enter a short description. Shorten it if it is too long.');message.focus();return false}
    if(/[|\r\n\t]/.test(landmark.value)||/[|\r\n\t]/.test(message.value)){alert('Use a single line without the | character.');return false}
    return true;
  };
  const close=()=>{if(busy||!modal.classList.contains('show'))return;modal.classList.remove('show');button.focus()};
  byId('confirmBack').onclick=close;
  document.addEventListener('keydown',event=>{
    if(!modal.classList.contains('show'))return;
    if(event.key==='Escape')close();
    if(event.key==='Tab'){
      const first=byId('confirmSend'),last=byId('confirmBack');
      if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
      else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
    }
  });
  const paint=()=>{
    const remaining=Math.max(0,Math.ceil((retryUntil-Date.now())/1000));
    button.disabled=busy||!!lastStatus.pending||remaining>0;
    indicator.textContent=remaining?'Anti-spam protection: wait '+remaining+'s before another report.':lastStatus.pending?'This node already has a report waiting for admin acknowledgment.':'Anti-spam protection active: one new report per client every 60 seconds. Saved-report retries continue automatically.';
    if(lastStatus.blocked_submissions)indicator.textContent+=' Blocked attempts on this node since restart: '+lastStatus.blocked_submissions+'.';
  };
  window.sendReport=async()=>{
    if(busy||button.disabled||!valid())return;
    busy=true;paint();
    try{
      reviewedPayload=new URLSearchParams({type:'ROAD_ACCIDENT',message:message.value.trim(),landmark:landmark.value.trim(),created_at:String(Math.floor(Date.now()/1000)),confirmed:'1'});
      const response=await fetch('/prepare',{method:'POST',body:reviewedPayload});
      if(!response.ok)throw new Error(await response.text());
      const proof=await response.json();reviewedPayload.set('confirmation',proof.confirmation);
    }catch(error){alert(error.message||'Could not prepare this report. Reconnect to the node and try again.');return}
    finally{busy=false;paint()}
    byId('confirmLocation').textContent=reviewedPayload.get('landmark');byId('confirmDescription').textContent=reviewedPayload.get('message');
    modal.classList.add('show');byId('confirmSend').focus();
  };
  byId('confirmSend').onclick=async()=>{
    if(busy||!valid())return;
    // Snapshot the values actually reviewed, preventing a changed form from
    // silently replacing the confirmation contents.
    if(byId('confirmLocation').textContent!==landmark.value.trim()||byId('confirmDescription').textContent!==message.value.trim()){close();return}
    busy=true;byId('confirmSend').disabled=true;paint();
    try{
      const response=await fetch('/send',{method:'POST',body:reviewedPayload}),text=await response.text();
      if(response.status===429)retryUntil=Date.now()+Number(response.headers.get('Retry-After')||60)*1000;
      if(response.ok){message.value='';landmark.value='';byId('remaining').textContent='70';retryUntil=Date.now()+60000;lastStatus.pending=true;modal.classList.remove('show')}
      else{modal.classList.remove('show');reviewedPayload=null}
      alert(text);
    }catch(error){alert('Could not reach this node. Reconnect to its Wi-Fi and check Latest Report before retrying.')}
    finally{busy=false;byId('confirmSend').disabled=false;await refresh();paint()}
  };
  const originalRefresh=window.refresh;
  window.refresh=async()=>{
    await originalRefresh();
    try{const response=await fetch('/status',{cache:'no-store'});lastStatus=await response.json();retryUntil=Date.now()+Number(lastStatus.retry_after_seconds||0)*1000;
      if(lastStatus.radio_profile){
        const age=lastStatus.radio_last_age_ms;
        const last=age==null?'No authenticated packet received yet.':'Last packet from NODE_'+lastStatus.radio_last_sender+': '+lastStatus.radio_last_rssi+' dBm; SNR '+lastStatus.radio_last_snr+' dB; '+Math.floor(age/1000)+'s ago'+(age>40000?' (historical reading)':'')+'.\nFrequency error: '+lastStatus.radio_last_frequency_error_hz+' Hz.';
        const queue=lastStatus.radio_tx_queue==null?'':'\nRadio queue: '+lastStatus.radio_tx_queue+'; completed transmissions: '+lastStatus.radio_tx_done+'\nChannel deferrals: '+lastStatus.radio_channel_deferrals+'; CAD timeouts: '+lastStatus.radio_cad_timeouts+'\nTX failures: '+lastStatus.radio_tx_failed+'; queue drops: '+lastStatus.radio_queue_dropped+'; expired: '+lastStatus.radio_queue_expired+'\nThese counters are not a measured packet-loss percentage.';
        radioDetails.querySelector('[data-radio-diagnostics]').textContent=lastStatus.radio_profile+'\n'+lastStatus.radio_revision+'\n'+(lastStatus.radio_frequency_hz/1e6)+' MHz; SF'+lastStatus.radio_sf+'; BW '+(lastStatus.radio_bandwidth_hz/1000)+' kHz; CR '+lastStatus.radio_coding_rate+'; TX '+lastStatus.radio_tx_dbm+' dBm\n'+last+'\nValid packets since restart: '+lastStatus.radio_valid_packets+'\nAuthentication/replay rejections since restart: '+(lastStatus.radio_rejections||0)+queue+(lastStatus.radio_sf===9?'\nISOLATED PAIR TEST ONLY: this setting cannot join the SF7 mesh.':'');
      }
      if(lastStatus.created_at&&/^\d+$/.test(lastStatus.created_at))byId('created').textContent=new Date(Number(lastStatus.created_at)*1000).toLocaleString();
      if(lastStatus.verification)byId('status').textContent=(lastStatus.report_status||'')+' · '+lastStatus.verification;
    }catch(error){}paint();
  };
  // Original interval captures its old callback; a fresh lightweight update
  // also keeps the cooldown indicator authoritative while reports are pending.
  setInterval(()=>window.refresh(),2000);window.refresh();
})();
</script>
)guard";
