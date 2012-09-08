var $req;
var $reqnum;
var $timeout;
try
  {
  $req = new XMLHttpRequest();
  } catch(e)
    {
    try
      {
      $req = new ActiveXObject('Msxml2.XMLHTTP');
      } catch(e)
        {
        try
          {
          $req = new ActiveXObject('Microsoft.XMLHTTP');
          } catch(e)
            {
            $req = null;
            }
        }
    }

function makeRequest(rx, msg, extra)
{
if ($req)
  {
  $req.open('POST', rx, true);
  $req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  $req.onreadystatechange = catch_send;
  $req.send('message=' + msg + ((extra != null) ? ('&' + extra) : ''));
  $reqnum++;
  $timeout = setTimeout(abort_request, 10000);
  }
}

function catch_send()
{
var resp;
try // https://bugzilla.mozilla.org/show_bug.cgi?id=238559
  {
  if ($req.readyState == 4)
    {
    clearTimeout($timeout);
    if ($req.status == 200)
      {
      resp = $req.responseXML.documentElement;
      parse_received(
        resp.getElementsByTagName('result')[0],
        resp.getElementsByTagName('method')[0].firstChild.data
        );
      }
    $enableupdate = 1;
    }
  $reqnum--;
  } catch(e) {};
}

function abort_request()
{
    $req.abort();
    $enableupdate=1;
}  