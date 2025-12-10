from flask import Flask, redirect, session, request, url_for, render_template
from google_auth_oauthlib.flow import Flow
from googleapiclient.discovery import build
import datetime
import os

app = Flask(__name__)
app.secret_key = 'your_secret'
os.environ['OAUTHLIB_INSECURE_TRANSPORT'] = '1'

SCOPES = ['https://www.googleapis.com/auth/calendar.readonly']
CLIENT_SECRETS_FILE = 'credentials.json'

@app.route('/')
def index():
    return render_template('index.html')

@app.route('/authorize')
def authorize():
    flow = Flow.from_client_secrets_file(CLIENT_SECRETS_FILE, scopes=SCOPES,
                                         redirect_uri=url_for('oauth2callback', _external=True))
    authorization_url, _ = flow.authorization_url(prompt='consent')
    session['flow'] = flow
    return redirect(authorization_url)

@app.route('/oauth2callback')
def oauth2callback():
    flow = session['flow']
    flow.fetch_token(authorization_response=request.url)
    credentials = flow.credentials
    session['credentials'] = credentials_to_dict(credentials)
    return redirect(url_for('get_events'))

@app.route('/get_events')
def get_events():
    credentials = session['credentials']
    service = build('calendar', 'v3', credentials=credentials)
    
    now = datetime.datetime.utcnow().isoformat() + 'Z'
    events_result = service.events().list(calendarId='primary', timeMin=now,
                                          maxResults=10, singleEvents=True,
                                          orderBy='startTime').execute()
    events = events_result.get('items', [])

    silent_schedule = []
    for event in events:
        start = event['start'].get('dateTime', event['start'].get('date'))
        end = event['end'].get('dateTime', event['end'].get('date'))
        summary = event.get('summary', 'No Title')
        silent_schedule.append({'summary': summary, 'start': start, 'end': end})
    
    return {'silent_times': silent_schedule}

def credentials_to_dict(credentials):
    return {
        'token': credentials.token,
        'refresh_token': credentials.refresh_token,
        'token_uri': credentials.token_uri,
        'client_id': credentials.client_id,
        'client_secret': credentials.client_secret,
        'scopes': credentials.scopes
    }

if __name__ == '__main__':
    app.run(debug=True)
