import sys, os
INTERP = "/home/thundergoblin/badmin.robnugen.com/image_renamer/bin/python3"
# INTERP is present twice so that the new python interpreter
# knows the actual executable path

if sys.executable != INTERP: os.execl(INTERP, INTERP, *sys.argv)

cwd = os.getcwd()
sys.path.append(cwd)
sys.path.append(cwd + '/django_renamer')

sys.path.insert(0,cwd+'/image_renamer/bin')
sys.path.insert(0,cwd+'/image_renamer/lib/python3.8/site-packages/')

os.environ['DJANGO_SETTINGS_MODULE'] = "django_renamer.settings"
from django.core.wsgi import get_wsgi_application
application = get_wsgi_application()

    
