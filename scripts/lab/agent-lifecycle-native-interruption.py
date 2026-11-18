"""Debian/systemd lab only; never run on the registered host namespace."""
import base64,hashlib,http.server,json,os,pathlib,pwd,shutil,signal,socket,sqlite3,ssl,subprocess,sys,tempfile,threading,time,uuid
P=pathlib.Path
ROOT=P('/lab/publish064')
assert P('/run/systemd/container').is_file() and 'nspawn' in P('/run/systemd/container').read_text()
assert P('/proc/1/comm').read_text().strip()=='systemd'
RESULT=ROOT/'native-result.json'
def run(*args):return subprocess.run(args,capture_output=True,check=True,timeout=60)
def write(path,data,mode=0o600,uid=0,gid=0):
 path.parent.mkdir(parents=True,exist_ok=True)
 fd,temp=tempfile.mkstemp(dir=path.parent)
 with os.fdopen(fd,'wb') as out:
  os.fchmod(out.fileno(),mode);os.fchown(out.fileno(),uid,gid);out.write(data);out.flush();os.fsync(out.fileno())
 os.replace(temp,path)
def hash_(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def counts():
 with sqlite3.connect('file:/var/lib/agent-center-agent/journal.sqlite3?mode=ro',uri=True) as db:
  assert db.execute('pragma quick_check').fetchone()[0]=='ok'
  return {t:db.execute('select count(*) from '+t).fetchone()[0] for t in ('executions','events')}
if len(sys.argv)>1 and sys.argv[1]=='--apply':
 sys.path.insert(0,str(ROOT/'agent.pyz'))
 from agent_center.lifecycle.manager import Manager
 from agent_center.lifecycle.runtime import RuntimeAdapter
 from agent_center.update.remote import Remote
 from agent_center.update.engine import Engine
 base=P('/opt/agent-center-agent');state=P('/var/lib/agent-center-agent');config=P('/etc/agent-center-agent');control=P('/var/lib/agent-center-updater')
 remote=Remote(Engine(base,state),None,[],control,config)
 operation={'kind':'update','phase':'apply','from_version':'0.6.4','release':{'device_operations_frozen':True}}
 adapter=RuntimeAdapter(remote,ROOT/'agent.pyz',operation)
 result=Manager(control/'publish064-interruption',adapter,(ROOT/'signer-public.pem').read_bytes(),'debian13').apply(sys.argv[2],(ROOT/'manifest.json').read_bytes(),(ROOT/'signature.base64').read_text().strip(),(ROOT/'agent.pyz').read_bytes())
 raise SystemExit(0 if result['state']=='COMPLETED' else 2)
try:
 os.umask(0o077)
 run('/usr/bin/systemctl','stop','agent-center-updater.timer')
 run('/usr/bin/systemctl','stop','agent-center-updater.service')
 run('/usr/bin/systemctl','stop','agent-center-agent.service')
 BASE=P('/opt/agent-center-agent');STATE=P('/var/lib/agent-center-agent');CONFIG=P('/etc/agent-center-agent');CONTROL=P('/var/lib/agent-center-updater')
 backup=ROOT/'lab-before';backup.mkdir(mode=0o700,exist_ok=True)
 for path,label in ((BASE,'base'),(CONFIG,'config'),(STATE,'state'),(CONTROL,'control')):
  if not (backup/label).exists():shutil.copytree(path,backup/label,symlinks=True)
 identity=json.loads((STATE/'identity.json').read_text()); identity_hash=hash_(STATE/'identity.json');before=counts()
 # Verify the real candidate with the public production key, never its private key.
 sys.path.insert(0,str(BASE/'remote-updater.pyz'))
 from agent_center.lifecycle.releases import verify
 payload=(ROOT/'manifest.json').read_bytes();signature=(ROOT/'signature.base64').read_text().strip();artifact=(ROOT/'agent.pyz').read_bytes()
 release=verify(payload,signature,(ROOT/'signer-public.pem').read_bytes(),artifact,minimum_sequence=4,platform='debian13')
 # New signed code is loaded in a separate process via an authenticated archive.
 for name in list(sys.modules):
  if name=='agent_center' or name.startswith('agent_center.'):
   del sys.modules[name]
 sys.path[0]=str(ROOT/'agent.pyz')
 from agent_center.lifecycle.manager import Manager
 from agent_center.lifecycle.runtime import RuntimeAdapter
 from agent_center.lifecycle.updaters import Updaters
 from agent_center.lifecycle.updater_bootstrap import BootstrapAdapter
 from agent_center.update.remote import Remote
 from agent_center.update.engine import Engine
 from agent_center.state import atomic_write,read_private
 principal=pwd.getpwnam('agent-center-agent')
 cert=ROOT/'fixture.crt';key=ROOT/'fixture.key'
 run('/usr/bin/openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1')
 cert.chmod(0o644)
 shutil.copyfile(cert,P('/usr/local/share/ca-certificates/agent-publish-fixture.crt'))
 run('/usr/sbin/update-ca-certificates')
 policy={'schema_version':1,'agent_id':identity['agent_id'],'tasks_enabled':True,'task_protocol':1,'device_operations_frozen':True,'allowed_capabilities':['agent_info','icmp_ping:v1','dns_lookup:v1','tcp_connect:v1','traceroute:v1','http_probe:v1','mtr_path_analysis:v1']}
 fail_new=threading.Event()
 class Handler(http.server.BaseHTTPRequestHandler):
  def log_message(self,*args):pass
  def do_POST(self):
   size=int(self.headers.get('Content-Length','0'));data=json.loads(self.rfile.read(size));op=self.path.rsplit('/',1)[-1]
   if self.headers.get('Authorization')!='Bearer '+identity['token']:self.send_error(401);return
   if op=='heartbeat' and fail_new.is_set() and data.get('version')=='0.6.4':self.send_error(401);return
   body={'status':'ok','policy':policy}
   if op=='capabilities':body={'capabilities':data['capabilities']}
   elif op=='claim':body={'task':None,'policy':policy}
   elif op=='reconcile':body={'executions':[]}
   raw=json.dumps(body).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
 server=http.server.ThreadingHTTPServer(('127.0.0.1',18444),Handler)
 context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);server.socket=context.wrap_socket(server.socket,server_side=True)
 threading.Thread(target=server.serve_forever,daemon=True).start()
 cfg=json.loads((CONFIG/'config.json').read_text());cfg.update(central_url='https://127.0.0.1:18444',heartbeat_interval=1,poll_interval=1,timeout=1)
 write(CONFIG/'config.json',json.dumps(cfg).encode(),uid=principal.pw_uid,gid=principal.pw_gid)
 envelope=read_private(CONFIG/'lifecycle-policy.json')
 envelope['components']=list(dict.fromkeys(envelope['components']+['lifecycle-updater']));envelope['profiles']=list(dict.fromkeys(envelope['profiles']+['lifecycle-updater']))
 atomic_write(CONFIG/'lifecycle-policy.json',envelope)
 run('/usr/bin/systemctl','daemon-reload');run('/usr/bin/systemctl','start','agent-center-mtr.socket');run('/usr/bin/systemctl','start','agent-center-agent.service')
 def online(version):
  for _ in range(45):
   status=json.loads((STATE/'status.json').read_text())
   if status.get('version')==version and status.get('state')=='ONLINE' and status.get('heartbeat_successes',0)>0 and status.get('poll_successes',0)>0:return
   time.sleep(1)
  raise RuntimeError('lab_not_online')
 old_version=json.loads((STATE/'status.json').read_text())['version'];online(old_version)
 # Validate the actual hardened companion service and its stable ZIP copy.
 operations=json.loads((CONFIG/'operations.json').read_text());operations['central_url']='https://127.0.0.1:18444'
 write(CONFIG/'operations.json',json.dumps(operations).encode())
 write(CONFIG/'lifecycle-public.pem',(ROOT/'signer-public.pem').read_bytes())
 run('/usr/bin/systemctl','start','agent-center-updater.service')
 assert run('/usr/bin/systemctl','show','agent-center-updater.service','--value','-p','ExecMainStatus').stdout.strip()==b'0','stable_companion_service_failed'
 # Kill a real Manager during CHECKING, then verify hold, restoration and journal preservation.
 operation_id=str(uuid.uuid4());interrupted=CONTROL/'publish064-interruption'
 fail_new.set()
 child=subprocess.Popen([sys.executable,str(P(__file__)),'--apply',operation_id],start_new_session=True)
 deadline=time.monotonic()+30
 while time.monotonic()<deadline:
  marker=interrupted/(operation_id+'.json')
  if marker.exists() and json.loads(marker.read_text()).get('state')=='CHECKING':break
  if child.poll() is not None:raise RuntimeError('interruption_window_not_reached')
  time.sleep(.05)
 else:raise RuntimeError('interruption_window_timeout')
 os.killpg(child.pid,signal.SIGKILL);child.wait(timeout=3)
 run('/usr/bin/systemctl','stop','agent-center-agent.service')
 remote=Remote(Engine(BASE,STATE),None,[],CONTROL,CONFIG)
 operation={'kind':'update','phase':'apply','from_version':'0.6.4','release':{'device_operations_frozen':True}}
 adapter=RuntimeAdapter(remote,ROOT/'agent.pyz',operation)
 from agent_center.lifecycle.releases import ReleaseError
 manager=Manager(interrupted,adapter,(ROOT/'signer-public.pem').read_bytes(),'debian13')
 try:manager.apply(str(uuid.uuid4()),payload,signature,artifact)
 except ReleaseError as error:assert str(error)=='recovery_required'
 else:raise RuntimeError('interruption_hold_bypassed')
 # Append a newer committed journal entry as the principal before rollback.
 code="import sys;sys.path.insert(0,'/opt/agent-center-agent/runtime/agent.pyz');from agent_center.journal import Journal;from pathlib import Path;j=Journal(Path('/var/lib/agent-center-agent/journal.sqlite3'));j.run_once('new-after-interruption','lab-preservation',lambda:{'ok':True});j.close()"
 run('/usr/sbin/runuser','-u','agent-center-agent','--','/usr/bin/python3','-I','-c',code)
 after_append=counts();assert after_append['executions']==before['executions']+1
 fail_new.clear();adapter.precheck(release,artifact);adapter.rollback(operation_id);assert adapter.rollback_health()
 record=manager.reconcile_restored(operation_id,hashlib.sha256(payload).hexdigest())
 assert record['state']=='FAILED' and record['recovery_verified'] is True
 assert counts()==after_append and hash_(STATE/'identity.json')==identity_hash
 write(ROOT/'native-interruption-result.json',json.dumps({'passed':True,'native_systemd':True,'stable_companion_service_exit':0,'interrupted_checking_hold_enforced':True,'verified_recovery':True,'new_journal_entries_preserved':True,'journal_before':before,'journal_after':counts(),'identity_preserved':True,'external_probes':False,'production_agent_touched':False},indent=2).encode())
except BaseException as error:
 write(ROOT/'native-interruption-result.json',json.dumps({'passed':False,'error_type':type(error).__name__,'reason':str(error)[:240] if isinstance(error,(AssertionError,RuntimeError)) else 'native_lab_failed'},indent=2).encode())
 raise
