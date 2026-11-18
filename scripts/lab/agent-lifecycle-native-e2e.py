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
 old_binaries={str(p):hash_(p) for p in (BASE/'runtime/agent.pyz',BASE/'updater.pyz',BASE/'remote-updater.pyz',P('/opt/agent-center-mtr/helper.pyz'))}
 # Actual OS rollback following a real HTTP authentication failure for the new runtime.
 work=CONTROL/'publish064-validation';work.mkdir(mode=0o700,exist_ok=False)
 remote=Remote(Engine(BASE,STATE),None,[],CONTROL,CONFIG)
 operation={'kind':'update','phase':'apply','from_version':old_version,'release':{'device_operations_frozen':True}}
 adapter=RuntimeAdapter(remote,ROOT/'agent.pyz',operation)
 fail_new.set()
 failed=Manager(work,adapter,(ROOT/'signer-public.pem').read_bytes(),'debian13').apply(str(uuid.uuid4()),payload,signature,artifact)
 assert failed['state']=='FAILED' and failed.get('rollback') is True,failed
 fail_new.clear();online(old_version)
 assert old_binaries=={name:hash_(P(name)) for name in old_binaries},'binary_rollback_mismatch'
 assert hash_(STATE/'identity.json')==identity_hash and counts()==before,'state_rollback_violation'
 # Real legacy companion transition through a separate Lifecycle ledger.
 bootstrap=CONTROL/'publish064-bootstrap-validation';bootstrap.mkdir(mode=0o700)
 bootstrap_result=Manager(bootstrap,BootstrapAdapter(Updaters(BASE,CONFIG),bootstrap),(ROOT/'signer-public.pem').read_bytes(),'debian13').apply(str(uuid.uuid4()),payload,signature,artifact)
 assert bootstrap_result['state']=='COMPLETED',bootstrap_result
 assert hash_(BASE/'runtime/agent.pyz')==old_binaries[str(BASE/'runtime/agent.pyz')]
 # Full signed update: runtime, MTR and both companions in the native system.
 adapter=RuntimeAdapter(remote,ROOT/'agent.pyz',operation)
 success=Manager(work,adapter,(ROOT/'signer-public.pem').read_bytes(),'debian13').apply(str(uuid.uuid4()),payload,signature,artifact)
 assert success['state']=='COMPLETED',success
 online('0.6.4')
 from agent_center.lifecycle.updaters import derive
 assert all((BASE/name).read_bytes()==derive(artifact,name) for name in ('updater.pyz','remote-updater.pyz'))
 assert hash_(STATE/'identity.json')==identity_hash and counts()==before,'state_install_violation'
 output={'passed':True,'platform':'debian13','native_systemd':True,'real_http_postcheck_failure_rolled_back':True,'all_binary_hashes_restored_after_failure':True,'legacy_companion_transition':'COMPLETED','full_signed_update':'COMPLETED','version':'0.6.4','identity_preserved':True,'journal_before':before,'journal_after':counts(),'sequence':5,'source_artifact_sha256':hashlib.sha256(artifact).hexdigest(),'external_probes':False,'production_agent_touched':False}
 write(RESULT,json.dumps(output,indent=2).encode())
except BaseException as error:
 write(RESULT,json.dumps({'passed':False,'error_type':type(error).__name__,'reason':str(error)[:240] if isinstance(error,(AssertionError,RuntimeError)) else 'native_lab_failed'},indent=2).encode())
 raise
