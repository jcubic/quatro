/**@license
 *  _____
 * |_   _|___ ___ ___ ___ ___
 *   | | | .'| . | . | -_|  _|
 *   |_| |__,|_  |_  |___|_|
 *           |___|___|   version 0.1.0
 *
 * Tagger - Vanilla JavaScript Tag Editor
 *
 * Copyright (c) 2018 Jakub Jankiewicz <http://jcubic.pl/me>
 * Released under the MIT license
 */
/* global define, module, global */
(function(root, factory, undefined) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = function(root) {
            return factory();
        };
    } else {
        root.tagger = factory();
    }
})(typeof window !== 'undefined' ? window : global, function(undefined) {
    // ------------------------------------------------------------------------------------------
    function tagger(input, options) {
        if (!(this instanceof tagger)) {
            return new tagger(input, options);
        }
        var settings = merge({}, tagger.defaults, options);
        this.init(input, settings);
    }
    // ------------------------------------------------------------------------------------------
    function merge() {
        if (arguments.length < 2) {
            return arguments[0];
        }
        var target = arguments[0];
        [].slice.call(arguments).reduce(function(acc, obj) {
            if (is_object(obj)) {
                Object.keys(obj).forEach(function(key) {
                    if (is_object(obj[key])) {
                        if (is_object(acc[key])) {
                            acc[key] = merge({}, acc[key], obj[key]);
                            return;
                        }
                    }
                    acc[key] = obj[key];
                });
            }
            return acc;
        });
        return target;
    }
    // ------------------------------------------------------------------------------------------
    function is_object(arg) {
        if (typeof arg !== 'object' || arg === null) {
            return false;
        }
        return Object.prototype.toString.call(arg) === '[object Object]';
    }
    // ------------------------------------------------------------------------------------------
    function create(tag, attrs, children) {
        tag = document.createElement(tag);
        Object.keys(attrs).forEach(function(name) {
            if (name === 'style') {
                Object.keys(attrs.style).forEach(function(name) {
                    tag.style[name] = attrs.style[name];
                });
            } else {
                tag.setAttribute(name, attrs[name]);
            }
        });
        if (children !== undefined) {
            children.forEach(function(child) {
                var node;
                if (typeof child === 'string') {
                    node = document.createTextNode(child);
                } else {
                    node = create.apply(null, child);
                }
                tag.appendChild(node);
            });
        }
        return tag;
    }
    // ------------------------------------------------------------------------------------------
    tagger.defaults = {
        completion: {
            list: [],
            delay: 400,
            minLength: 2
        }
    };
    // ------------------------------------------------------------------------------------------
    tagger.fn = tagger.prototype = {
        init: function(input, options) {
            this.ul = document.createElement('ul');
            this.input = input;
            var wrapper = document.createElement('div');
            wrapper.className = 'tagger';
            this.input.setAttribute('hidden', 'hidden');
            var self = this;
            this.ul.addEventListener('click', function(event) {
                if (event.target.className.match(/close/)) {
                    self.remove_tag(event.target);
                    event.preventDefault();
                }
            });
            this.tags_from_input();
            var li = document.createElement('li');
            li.className = 'tagger-new';
            this.new_tag = document.createElement('input');
            li.appendChild(this.new_tag);
            this.new_tag.addEventListener('keypress', function(event) {
                if (event.keyCode === 13 || event.keyCode === 44) {
                    self.add_tag(self.new_tag.value.trim());
                    self.new_tag.value = '';
                    event.preventDefault();
                }
            });
            this.ul.appendChild(li);
            input.parentNode.replaceChild(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(this.ul);
        },
        tags_from_input: function() {
            this.tags = this.input.value.split(/\s*,\s*/).filter(Boolean);
            this.tags.forEach(this.add_tag.bind(this));
        },
        add_tag: function(name) {
            var close = ['a', {href: '#', 'class': 'close'}, ['\u00D7']];
            var a_atts = {href: '/tag/' + name, target: '_black'};
            var li = create('li', {}, [['a', a_atts, [['span', {}, [name]], close]]]);
            this.ul.insertBefore(li, this.new_tag.parentNode);
            this.tags.push(name);
            this.input.value = this.tags.join(', ');
        },
        remove_tag: function(close) {
            var li = close.closest('li');
            var name = li.querySelector('span').innerText;
            this.ul.removeChild(li);
            this.tags = this.tags.filter(function(tag) {
                return name !== tag;
            });
            this.input.value = this.tags.join(', ');
        }
    };
    // ------------------------------------------------------------------------------------------
    return tagger;
});
