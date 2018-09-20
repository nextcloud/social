(function () {

  var Social = function () {

    var elem = {
      socialInstanceNewAccount: null,
      socialSubmitNewAccount: null,
      socialListAccounts: null,
      socialSubmitAccountTest: null
    }

    var test = {

      newAccount: function () {
        var data = {
          instance: elem.socialInstanceNewAccount.val()
        }

        test.sendRequest('POST', data, '/user/account', test.newAccountResult)
      },

      newAccountResult: function (data) {
        if (data.status !== 1) {
          return
        }

        window.open(data.result.authorizationUrl, 'gettoken', 'width=500,height=550')

        // test.getAccounts()
      },

      getAccounts: function () {
        test.sendRequest('GET', {}, '/user/accounts', test.getAccountsResult)
      },

      getAccountsResult: function (data) {
        if (data.status !== 1) {
          return
        }

        elem.socialListAccounts.empty()
        for (var i = 0; i < data.result.accounts.length; i++) {
          var item = data.result.accounts[i]
          elem.socialListAccounts.append(
            $('<option>', {value: item.id}).text(item.account + '@' + item.service.address))
        }

        test.refreshData()
      },

      testAccount: function (accountId) {
        test.sendRequest('GET', {}, '/user/account/' + accountId + '/test', test.testAccountResult)
      },

      testAccountResult: function (data) {
        console.log(JSON.stringify(data))
      },

      getAccountStatuses: function (accountId) {
        test.sendRequest('GET', {}, '/user/account/' + accountId + '/statuses',
          test.getAccountStatusesResult)
      },

      getAccountStatusesResult: function (data) {
        console.log('Your posts: ' + JSON.stringify(data))
      },

      getAccountFollows: function (accountId) {
        test.sendRequest('GET', {}, '/user/account/' + accountId + '/follows',
          test.getAccountFollowsResult)
      },

      getAccountFollowsResult: function (data) {
        console.log('Your Follows: ' + JSON.stringify(data))
      },

      refreshData: function () {
        var accountId = elem.socialListAccounts.val()
        test.getAccountFollows(accountId)
        test.getAccountStatuses(accountId)
      },

      sendRequest: function (method, data, url, callback) {
        $.ajax({
          method: method,
          url: OC.generateUrl('/apps/social' + url),
          data: {data: data}
        }).done(function (res) {
          test.requestCallback(callback, res)
        }).fail(function () {
          console.log('fail to request')
        })
      },

      requestCallback: function (callback, result) {
        if (callback && (typeof callback === 'function')) {
          if (typeof result === 'object') {
            callback(result)
          } else {
            callback({status: -1})
          }

          return true
        }

        return false
      }

    }

    elem.socialInstanceNewAccount = $('#social-instance-new-account')
    elem.socialSubmitNewAccount = $('#social-submit-new-account')
    elem.socialListAccounts = $('#social-list-accounts')
    elem.socialSubmitAccountTest = $('#social-submit-account-test')

    elem.socialSubmitNewAccount.on('click', function () {
      test.newAccount()
    })

    elem.socialSubmitAccountTest.on('click', function () {
      test.testAccount(elem.socialListAccounts.val())
    })

    elem.socialListAccounts.on('change', function () {
      test.refreshData()
    })

    test.getAccounts()
  }

  if (OCA.Social === undefined) {
    OCA.Social = {}
  }
  OCA.Social.test = new Social()

})()
