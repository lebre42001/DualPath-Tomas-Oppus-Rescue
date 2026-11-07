#pragma once

// One isolated, text-only renderer for the field-node response cache. The main
// status poll calls window.renderReportResponses(x.responses || []).
const char REPORT_RESPONSES_SCRIPT[] PROGMEM = R"responses(
<script>
(()=>{
  const make=(tag,text)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;return el};
  const section=make('section');section.className='card';section.setAttribute('aria-labelledby','reportResponsesTitle');
  const heading=make('h3','Responder messages');heading.id='reportResponsesTitle';
  const note=make('p','The latest 16 responses received on this node. Match the message ID to your report. Older reports remain in the emergency team\'s server records.');note.className='small';
  const list=make('div','No responses received yet.');list.id='reportResponsesList';list.setAttribute('aria-live','polite');
  section.append(heading,note,list);(document.querySelector('main')||document.body).append(section);
  const modal=make('div');modal.id='reportResponsesModal';modal.className='modal';
  modal.style.cssText='display:none;position:fixed;z-index:30;inset:0;background:#0009;align-items:center;justify-content:center;padding:18px';
  modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','reportResponsesModalTitle');modal.setAttribute('aria-describedby','reportResponsesModalSummary');
  const box=make('div');box.className='modal-box box';box.style.cssText='background:#fff;width:100%;max-width:400px;max-height:85vh;overflow:auto;padding:22px;border-radius:18px;box-sizing:border-box;overflow-wrap:anywhere';
  const title=make('h2','Report update');title.id='reportResponsesModalTitle';
  const summary=make('p','The emergency team sent an update. Match the message ID to your report.');summary.id='reportResponsesModalSummary';
  const id=make('p'),status=make('p'),message=make('p'),time=make('p');
  id.id='reportResponsesMessageId';status.id='reportResponsesStatus';message.id='reportResponsesMessage';time.id='reportResponsesTime';
  const close=make('button','OK');close.type='button';close.id='reportResponsesClose';
  box.append(title,summary,id,status,message,time,close);modal.append(box);document.body.append(modal);
  const statuses={ACKNOWLEDGED:'Responder update received',REJECTED:'Report cancelled by admin'};
  const revision=x=>JSON.stringify([x.message_id,x.ack_at,x.report_status,x.ack_message]);
  const displayTime=value=>{
    if(!value)return 'Not recorded';
    const date=new Date(/^\d+$/.test(value)?Number(value)*1000:value);
    return Number.isNaN(date.getTime())?value:date.toLocaleString();
  };
  const seen=new Set();let items=[],fingerprint='',active=null,returnFocus=null;
  const wasSeen=key=>{if(seen.has(key))return true;try{return localStorage.getItem('report_response_seen_'+key)==='1'}catch(e){return false}};
  const showNext=()=>{
    if(active)return;
    // Oldest first for popups; the history itself stays newest first.
    const next=[...items].reverse().find(x=>!wasSeen(revision(x)));if(!next)return;
    active=next;returnFocus=document.activeElement;
    id.textContent='Message ID: '+next.message_id;status.textContent=statuses[next.report_status];
    message.textContent=next.ack_message||'Your emergency report has been reviewed.';
    time.textContent='Responded: '+displayTime(next.ack_at);
    modal.style.display='flex';modal.classList.add('show');if(close.focus)close.focus();
  };
  close.addEventListener('click',()=>{
    if(!active)return;const key=revision(active);seen.add(key);
    try{localStorage.setItem('report_response_seen_'+key,'1')}catch(e){}
    active=null;modal.style.display='none';modal.classList.remove('show');
    if(returnFocus&&returnFocus.isConnected!==false&&returnFocus.focus)returnFocus.focus();
    showNext();
  });
  modal.addEventListener('keydown',event=>{
    if(!active)return;
    // This dialog can overlay a prepared-report confirmation. Consume its
    // keys so the document-level confirmation handler cannot close that form.
    if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close.click()}
    if(event.key==='Tab'){event.preventDefault();event.stopPropagation();if(close.focus)close.focus()}
  });
  window.renderReportResponses=values=>{
    items=(Array.isArray(values)?values:[]).filter(x=>x&&x.message_id&&Object.prototype.hasOwnProperty.call(statuses,x.report_status)).map(x=>({
      message_id:String(x.message_id),created_at:String(x.created_at||''),report_status:x.report_status,
      ack_at:String(x.ack_at||''),ack_message:String(x.ack_message||''),
    })).sort((a,b)=>b.ack_at.localeCompare(a.ack_at)||b.message_id.localeCompare(a.message_id)).slice(0,16);
    const nextFingerprint=JSON.stringify(items);
    if(nextFingerprint!==fingerprint){
      fingerprint=nextFingerprint;list.replaceChildren();if(!items.length)list.textContent='No responses received yet.';
      for(const item of items){
        const row=make('details');row.style.cssText='margin-top:10px;padding:12px;background:#f4f7f5;border-radius:10px;overflow-wrap:anywhere';
        const label=make('summary',item.message_id+' - '+statuses[item.report_status]);
        const text=make('p',item.ack_message||'Your emergency report has been reviewed.');
        const dates=make('p','Submitted: '+displayTime(item.created_at)+' | Responded: '+displayTime(item.ack_at));dates.className='small';
        row.append(label,text,dates);list.append(row);
      }
    }
    showNext();
  };
})();
</script>
)responses";
