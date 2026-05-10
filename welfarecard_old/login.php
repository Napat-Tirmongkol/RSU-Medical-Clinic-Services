<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" type="text/css" href="style.css">
  <title> </title>

</head>
<body>
<center>
  <div class="image-cropper"><img id="pictureUrl" width="25%"></div>
  <p id="userId" hidden></p>
  <p id="displayName" hidden></p>
  <p id="getDecodedIDToken" hidden></p>
  <br>
</center>

<script src="https://static.line-scdn.net/liff/edge/2.1/sdk.js"></script>
<script>
  function runApp() {
    liff.getProfile().then(profile => {
      document.getElementById("pictureUrl").src = profile.pictureUrl;
      document.getElementById("userId").innerHTML = profile.userId;
      document.getElementById("displayName").innerHTML =  profile.displayName;
      document.getElementById("getDecodedIDToken").innerHTML = liff.getDecodedIDToken().email;
      url ="https://rsu.dodeep.co.th/fpdf/welfareuser.php?lineid="+profile.userId;
      location.replace(url);

    }).catch(err => console.error(err));
  }
  
  liff.init({ liffId: "1657627999-bpxPZazB" }, () => { 
    if (liff.isLoggedIn()){   runApp();   }
    else {        liff.login();      }
  }, err => console.error(err.code, error.message));
  
  
  
</script>


</body>
</html>
