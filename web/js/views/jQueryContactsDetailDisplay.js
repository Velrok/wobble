"use strict";

/**
 * Implements the ContactsDetailDisplay as a dialog which flies over all other elements (thank to css z-index).
 */
function jQueryContactsDetailDisplay(x,y) {
  this.e = $('<div class="dialog contactdetail"></div>').appendTo($('body')).css('display', 'none').css('left', x).css('top', y);
  this.contact = null;

  var that = this;
  this.e.click(function() {
    that.hide();
  });
};
jQueryContactsDetailDisplay.prototype = new ContactsDetailDisplay;
jQueryContactsDetailDisplay.prototype.constructor = jQueryContactsDetailDisplay;

jQueryContactsDetailDisplay.prototype.show = function(contact) {
  this.contact = contact;

  var template =  ' <div class="usericon usericon{{size}}">' +
          '   <div><img src="http://gravatar.com/avatar/{{img}}?s={{size}}" width="{{size}}" height="{{size}}"></div>' +
          '   <div class="status {{status}}"></div>' +
          ' </div>'+
          ' <div class=name>{{name}}</div>' +
          ' <div class=email>{{email}}</div>';

  var html = Mustache.to_html(template, {
    'img': contact.img,
    'name': contact.name,
    'email': contact.email,
    'status': contact.online == 1 ? 'online': 'offline',
    'size': 100
  });
  this.e.html(html).css('display', '');
};
jQueryContactsDetailDisplay.prototype.hide = function() {
  this.e.css('display', 'none');
  this.onClose();
  this.contact = null;
};

jQueryContactsDetailDisplay.prototype.addAction = function(title, callback) {
  $('<button></button>').text(title).click(callback).appendTo(this.e);
};