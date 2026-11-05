/* Isolated feed fixtures, never real reports. Playback-state tests simulate
   audio; a separate real-media test runs with browser speaker output muted. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),web=path.join(root,'web_system');
const output=path.join(__dirname,'runtime/report-alarm-fix');fs.mkdirSync(output,{recursive:true});
const payload=(id,channel='online',action='created')=>({action,report:{id,report_code:'FIXTURE-'+id,incident_type:'Road Accident',transmission_type:channel,lora_sender_id:channel==='lora'?'NODE_9':null,detail_url:'/reports/'+id}});
const reports=Array.from({length:16},(_,i)=>payload(i+1));
const feedRequests=[];let feedStatus=200;
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.startsWith('/modules/')){
  const file=path.basename(url.pathname);
  if(!['incoming-reports.js','report-alarm.js'].includes(file)){res.writeHead(404).end();return}
  res.setHeader('Content-Type','application/javascript');res.end(fs.readFileSync(path.join(web,'resources/js',file)));return;
 }
 if(url.pathname==='/alarm.mp3'){res.setHeader('Content-Type','audio/mpeg');res.end(fs.readFileSync(path.join(web,'public/sounds/incoming-report-alarm.mp3')));return}
 if(url.pathname==='/feed'){
  feedRequests.push(url.search);
  if(feedStatus!==200){res.writeHead(feedStatus).end('{}');return}
  const after=url.searchParams.has('after')?Number(url.searchParams.get('after')):null;
  const cursor=reports.at(-1)?.report.id||0;
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({initial:after===null,cursor,items:after===null?reports.slice(-20):reports.filter(x=>x.report.id>after)}));return;
 }
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><body>
 <h1>Report alarm</h1><button id="ordinary">Ordinary page action</button>
 <section class="incoming-alarm-toolbar"><small data-incoming-sound-status></small>
 <button data-incoming-sound>Enable sound</button><button data-incoming-sound-mute>Mute sound</button><button data-incoming-sound-stop hidden>Stop alarm</button></section>
 <button>Alerts <span data-alert-count>0</span></button>
 <aside data-incoming-reports data-user-id="1" data-feed-url="/feed" data-alarm-url="/alarm.mp3"><div data-alert-list></div><button data-incoming-clear>Mark all seen</button><small data-incoming-status></small></aside>
 <section data-incoming-banner hidden><strong data-incoming-banner-title></strong><p data-incoming-banner-copy></p><button data-incoming-sound data-incoming-sound-unlock hidden>Enable sound</button></section>
 <script type="module">import {initializeIncomingReports,receiveIncomingReport} from '/modules/incoming-reports.js';window.arrive=receiveIncomingReport;initializeIncomingReports(()=>{});</script></body></html>`);
});

(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,args:['--mute-audio']});
 try{
  const context=await browser.newContext();
  await context.addInitScript(()=>{
   window.NativeFixtureAudio=window.Audio;window.fixtureAudio=[];window.blockAudible=false;
   window.Audio=class extends EventTarget{
    constructor(url){super();this.src=url;this.muted=false;this.currentTime=0;this.paused=true;this.starts=[];window.fixtureAudio.push(this)}
    play(){if(window.blockAudible&&!this.muted)return Promise.reject(new DOMException('Activation required','NotAllowedError'));this.starts.push({muted:this.muted,loop:this.loop});this.paused=false;return Promise.resolve()}
    pause(){this.paused=true}load(){this.error=null}
   };
   // Include the obsolete persisted pending flag to test upgrade/reload recovery.
   if(!localStorage.getItem('fixture-seeded')){
    localStorage.setItem('fixture-seeded','1');
    localStorage.setItem('aksyr-incoming-1',JSON.stringify({cursor:3,seen:[],unread:[]}));
    localStorage.setItem('aksyr-report-alarm-1',JSON.stringify({enabled:true,pending:true}));
   }
  });
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install();
  const audible=()=>page.evaluate(()=>window.fixtureAudio[0].starts.filter(x=>!x.muted).length);
  const state=()=>page.evaluate(()=>({paused:window.fixtureAudio[0].paused,loop:window.fixtureAudio[0].loop,pref:JSON.parse(localStorage.getItem('aksyr-report-alarm-1'))}));
  const arrive=async(id,channel='online',action='created')=>{
   const event=payload(id,channel,action);if(action==='created'&&!reports.some(x=>x.report.id===id))reports.push(event);
   await page.evaluate(event=>window.arrive(event),event);
  };
  await page.goto(origin);await page.waitForFunction(()=>document.querySelector('[data-alert-count]').textContent==='16');
  assert.equal(feedRequests[0],'','A page load establishes a silent baseline even with a saved old cursor');
  assert.equal(await audible(),0,'Refresh and 16 existing reports must be silent');
  await page.locator('#ordinary').click();await page.keyboard.press('Tab');
  await page.waitForFunction(()=>document.querySelector('[data-incoming-sound]').textContent==='Test sound');
  assert.equal(await audible(),0,'An ordinary click/key must only prime muted audio');
  assert.equal((await state()).pref.pending,undefined,'A pending replay flag is no longer persisted');

  const duration=await page.evaluate(()=>new Promise((resolve,reject)=>{
   const audio=new window.NativeFixtureAudio('/alarm.mp3');audio.preload='metadata';
   audio.onloadedmetadata=()=>{const duration=audio.duration;audio.src='';resolve(duration)};audio.onerror=()=>reject(new Error('MP3 metadata unavailable'));audio.load();
  }));
  assert(Number.isFinite(duration)&&duration>0);
  await page.getByRole('button',{name:'Test sound',exact:true}).click();assert.equal(await audible(),1);
  await page.clock.fastForward(1201);assert.equal((await state()).paused,true);

  await arrive(17,'lora');assert.equal(await audible(),2);assert.equal((await state()).loop,true);
  await page.clock.fastForward(2200);assert.equal((await state()).paused,false,'A real arrival must not end at about two seconds');
  const polled=page.waitForResponse(r=>r.url().includes('/feed?after='));
  await page.clock.fastForward(3000);await polled;
  assert.equal(await audible(),2,'Echo/poll duplicates do not restart the alarm');
  await page.clock.fastForward(4801);assert.equal((await state()).paused,true,'The arrival stops at its original ten-second deadline');

  await page.reload();await page.waitForFunction(()=>document.querySelector('[data-alert-count]').textContent==='17');
  assert.equal(await audible(),0);
  await page.locator('#ordinary').click();assert.equal(await audible(),0);
  await page.evaluate(()=>window.blockAudible=true);
  await arrive(18);await page.getByRole('button',{name:'Play report alarm',exact:true}).first().waitFor();
  assert.equal(await audible(),0);
  await page.locator('#ordinary').click();assert.equal(await audible(),0,'Blocked arrivals never replay on unrelated clicks');
  await page.evaluate(()=>window.blockAudible=false);
  await page.getByRole('button',{name:'Play report alarm',exact:true}).first().click();assert.equal(await audible(),1);
  await page.getByRole('button',{name:'Stop alarm',exact:true}).click();assert.equal((await state()).paused,true);
  await arrive(19);assert.equal(await audible(),2);
  await page.getByRole('button',{name:'Mute sound',exact:true}).click();assert.equal((await state()).pref.enabled,false);
  await page.getByRole('button',{name:'Enable sound',exact:true}).first().click();assert.equal((await state()).pref.enabled,true);
  assert.equal(await audible(),2,'Re-enabling primed audio is silent and saves the preference immediately');
  await page.getByRole('button',{name:'Mute sound',exact:true}).click();
  await page.locator('#ordinary').click();await arrive(20);assert.equal(await audible(),2);
  await page.reload();await page.waitForFunction(()=>document.querySelector('[data-alert-count]').textContent==='20');
  await page.locator('#ordinary').click();assert.equal(await audible(),0);assert.equal((await state()).pref.enabled,false);
  await page.getByRole('button',{name:'Enable sound',exact:true}).first().click();assert.equal(await audible(),0);
  await arrive(21);assert.equal(await audible(),1);
  await page.getByRole('button',{name:'Mark all seen'}).click();assert.equal((await state()).paused,true);
  await arrive(21,'online','updated');assert.equal(await audible(),1,'A status edit is not a new alarm');

  feedStatus=401;const denied=page.waitForResponse(r=>r.url().includes('/feed')&&r.status()===401);
  await page.clock.fastForward(5001);await denied;
  await page.waitForFunction(()=>document.querySelector('[data-incoming-status]').textContent.includes('Sign in'));
  await arrive(22);assert.equal(await audible(),1,'Expired receiver authorization stops further alarms');
  assert.deepEqual(errors,[]);
  await context.close();
  feedStatus=200;
  const nativeContext=await browser.newContext();
  await nativeContext.addInitScript(()=>{
   const NativeAudio=window.Audio;window.mediaStarts=[];
   window.Audio=function(url){const audio=new NativeAudio(url);window.realAlarmAudio=audio;const play=audio.play.bind(audio);
    audio.play=()=>{window.mediaStarts.push({muted:audio.muted,at:Date.now()});return play()};return audio};
  });
  const nativePage=await nativeContext.newPage();await nativePage.goto(origin);
  await nativePage.waitForFunction(()=>document.querySelector('[data-alert-count]').textContent==='20');
  await nativePage.locator('#ordinary').click();
  await nativePage.waitForFunction(()=>document.querySelector('[data-incoming-sound]').textContent==='Test sound');
  assert(await nativePage.evaluate(()=>window.mediaStarts.every(x=>x.muted)&&window.realAlarmAudio.paused&&!window.realAlarmAudio.muted));
  await nativePage.evaluate(event=>window.arrive(event),payload(23));
  await nativePage.waitForFunction(()=>!window.realAlarmAudio.paused&&window.realAlarmAudio.currentTime>.05);
  await nativePage.waitForTimeout(2200);
  assert(await nativePage.evaluate(()=>!window.realAlarmAudio.paused&&window.realAlarmAudio.currentTime>1.8),'Real MP3 playback must continue beyond two seconds');
  await nativePage.waitForFunction(()=>window.realAlarmAudio.paused,undefined,{timeout:12000});
  const elapsed=await nativePage.evaluate(()=>Date.now()-window.mediaStarts.find(x=>!x.muted).at);
  assert(elapsed>=9500&&elapsed<13000,'Real media uses the ten-second alarm deadline');
  await nativeContext.close();
  const result={passed:true,sourceAudioDurationSeconds:duration,realMediaAlarmElapsedMs:elapsed,historyCount:16,checks:['silent refresh/click/key','explicit test only','10-second real arrivals','Echo/poll deduplication','blocked sound recovery','Stop/Mute/re-enable','stale pending migration','authorization expiry','native MP3 playback beyond two seconds'],physicalSpeakerOutput:'Not measured; isolated browser output was muted.'};
  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
 }finally{await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve))}
})().catch(e=>{console.error(e);server.closeAllConnections();server.close();process.exitCode=1});
