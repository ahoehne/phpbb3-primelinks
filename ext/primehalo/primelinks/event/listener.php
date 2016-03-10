<?php
namespace primehalo\primelinks\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $enable_general			= true;				// Enable this MOD for posts, private messages, and other parsed blocks of text?
	private $enable_forums			= true;				// Enable this MOD for forum links?
	private $enable_style			= true;				// Enable the CSS styling?
	private $enable_members			= true;				// Enable for member website links?

	// General Options
	private $use_target_attribute	= false;			// The attribute "target" is not valid for STRICT doctypes.
	private $internal_hide_guests	= false;			// Hide internal links from guests? If this is a string then the link text will be replaced with this string.
	private $external_hide_guests	= false;			// Hide external links from guests? If this is a string then the link text will be replaced with this string.
	private $external_link_prefix	= '';				// Prefix external links with this string. Example: 'http://anonym.to?'
	private $internal_link_domains	= '';				// List of domains to be considered local, separated by semicolons. Example: 'http://www.alternate-domain.com'
	private $forbidden_domains		= '';				// List of domains for which links should be removed, separated by semicolons. Example: 'http://www.porn.com'
	private $forbidden_new_url		= '#';				// URL to insert in place of any removed links. Example: 'http://www.google.com' or '#top'
	private $internal_link_rel		= '';				// Internal link relationship
	private $external_link_rel		= 'nofollow';		// External link relationship
	private $internal_link_target	= '';				// Internal link target (setting to FALSE will remove the link)
	private $external_link_target	= '_blank';			// External link target (setting to FALSE will remove the link)
	private $internal_link_class	= 'postlink-local';	// Internal link class
	private $external_link_class	= 'postlink';		// External link class

	private $domain_whitelist_included = false;

	// Special cases for links with specific file types. Separate file extensions with a vertical bar (|).
	private $file_type_classes = array(					// Give links with these file types a specific class name
		'pdf'						=> 'pdf-link',		// PDF files
		'gif|jpg|jpeg|png|bmp'		=> 'img-link',		// Image files
		'zip|rar|7z'				=> 'zip-link',		// Archive files
		// Add more as you see fit...
	);
	private $external_link_types	= '';				// Make these file types external links. Example: 'pdf|gif|jpg|jpeg|png|bmp|zip|rar|7z'
	private $internal_link_types	= '';				// Make these file types internal links. Example: 'pdf|gif|jpg|jpeg|png|bmp|zip|rar|7z'
	private $skip_link_types		= '';				// Don't process links to these file types. Example: 'pdf|gif|jpg|jpeg|png|bmp|zip|rar|7z'
	private $skip_prefix_types		= '';				// Don't add an external link prefix for these file types. Example: 'pdf|gif|jpg|jpeg|png|bmp|zip|rar|7z'

	// Variables
	protected $template;
	protected $user;
	protected $board_url;
	protected $board_host;

	public function __construct(\phpbb\template\template $template, \phpbb\user $user)
	{
		$this->template = $template;
		$this->user = $user;
		$this->board_url = generate_board_url(true);
		$this->board_url = utf8_case_fold_nfc($this->board_url);
		$this->board_host = $this->extract_host($this->board_url);
	}

	static public function getSubscribedEvents()
	{
		return array(
			'core.display_forums_modify_template_vars'	=> 'update_forum_links',
			'core.page_header' 							=> 'enable_style',
			'core.memberlist_prepare_profile_data'		=> 'memberlist_prepare_profile_data',
			'core.modify_format_display_text_after'		=> 'modify_format_display_text_after',	// Occurs in message_parser.php's format_display()
			'core.modify_text_for_display_after'		=> 'modify_text_for_display_after',		// Occurs in function_content.php's generate_text_for_display()
		);
	}

	/**
	* Update links in posts, signatures, private messages, and many others.
	*/
	public function modify_text_for_display_after($event)
	{
		if (empty($this->enable_general))
		{
			return;
		}
		$text = $event['text'];
		$text = $this->modify_links($text);
		$event['text'] = $text;
	}

	/**
	* Update links in messages
	*/
	function modify_format_display_text_after($event)
	{
		if (empty($this->enable_general))
		{
			return;
		}
		$text = $event['text'];
		$text = $this->modify_links($text);
		$event['text'] = $text;
	}

	/**
	* Set template variables to update the attributes of a member's website link
	*/
	public function memberlist_prepare_profile_data($event)
	{
		if (empty($this->enable_members))
		{
			return;
		}
		$this->template->assign_var('PRIME_LINK_TARGET',  $this->external_link_target);
		$this->template->assign_var('PRIME_LINK_REL', $this->external_link_rel);
	}

	/**
	* Set template variable to allow CSS styling of Prime Links
	*/
	public function enable_style($event)
	{
		if (empty($this->enable_style))
		{
			return;
		}
		$this->template->assign_var('PRIME_LINKS_STYLE', true);
	}

	/**
	* Update link attributes for forum links (viewforum.php)
	*/
	public function update_forum_links($event)
	{
		if (empty($this->enable_forums))
		{
			return;
		}
		$forumrow = $event['forum_row'];
		if (!empty($forumrow['S_IS_LINK']) && !$this->is_link_local($forumrow['U_VIEWFORUM']))
		{
			if ($this->external_link_target || $this->external_link_rel)
			{
				if (!empty($this->external_link_target))
				{
					$forumrow['PRIME_LINK_TARGET'] = $this->external_link_target;
				}
				if (!empty($this->external_link_rel))
				{
					$forumrow['PRIME_LINK_REL'] = $this->external_link_rel;
				}
				$event['forum_row'] = $forumrow;
			}
		}
	}

	/**
	* Decodes all HTML entities. The html_entity_decode() function doesn't decode numerical entities,
	* and the htmlspecialchars_decode() function only decodes the most common form for entities.
	*/
	function decode_entities($text)
	{
		$text = html_entity_decode($text, ENT_QUOTES, 'ISO-8859-1');		// UTF-8 does not work!
		$text = preg_replace('/&#(\d+);/me', 'chr($1)', $text);			 	// Decimal notation
		$text = preg_replace('/&#x([a-f0-9]+);/mei', 'chr(0x$1)', $text);	// Hex notation
		return($text);
	}

	/**
	* Extract the host portion of a URL (the domain plus any subdomains)
	*/
	function extract_host($url)
	{
		// Remove everything before and including the double slashes
		if (($double_slash_pos = strpos($url, '//')) !== false)
		{
			$url = substr($url, $double_slash_pos + 2);
		}

		// Remove everything after the domain, including the slash
		if (($domain_end_pos = strpos($url, '/')) !== false)
		{
			$url = substr($url, 0, $domain_end_pos);
		}
		return $url;
	}

	/**
	* Determine if the URL contains a domain.
	* $domains	: list of domains (an array or a string separated by semicolons)
	* $remove	: list of subdomains to remove (or TRUE/FALSE to remove all/none)
	*/
	function match_domain($url, $domains)
	{
		$url = $this->extract_host($url);
		$url = utf8_case_fold_nfc($url);
		$url_split = array_reverse(explode('.', $url));

		$domain_list = is_string($domains) ? explode(';', $domains) : $domains;
		foreach ($domain_list as $domain)
		{
			$domain = $this->extract_host($domain);
			$domain = utf8_case_fold_nfc($domain);

			// Ignoring all subdomains, so check if our URL ends with domain
			if (substr($url, -strlen($domain)) == $domain)
			{
				return true;
			}
			$domain_split = array_reverse(explode('.', $domain));
			$match_count = 0;
			$match_list = array();
			foreach ($domain_split as $index => $segment)
			{
				if (isset($url_split[$index]) && strcmp($url_split[$index], $segment) === 0)
				{
					$match_count += 1;
					array_splice($match_list, 0, 0, $segment);
					continue;
				}
				break;
			}
			if ($match_count > 2 || ($match_count == 2 && strlen($match_list[0]) > 2)) // not the best check, but catches domains like 'co.jp'
			{
				return true;
			}
		}
		return false;
	}

	/**
	* Determines if a URL is local or external. If no valid-ish scheme is found,
	* assume a relative (thus internal) link that happens to contain a colon (:).
	*/
	function is_link_local($url)
	{
		$url = strtolower($url);

		// Compare the URLs
		if (!($is_local = $this->match_domain($url, $this->board_url)))
		{
			// If there is no scheme, then it's probably a relative, local link
			$scheme = substr($url, 0, strpos($url, '://'));
			//$is_local = !$scheme || ($scheme && !in_array($scheme, array('http', 'https', 'mailto', 'ftp', 'gopher')));
			$is_local = !$scheme || ($scheme && !preg_match('/^[a-z0-9.]{2,16}$/i', $scheme));
		}

		$this->include_whitelist();
		// Not local, now check forced local domains
		if (!$is_local && $this->internal_link_domains)
		{
			$is_local = $this->match_domain($url, $this->internal_link_domains);
		}
		return($is_local);
	}

	/**
	 * Includes domain_whitelist.php in ext/primehalo/primelinks if there is a custom file
	 */
	function include_whitelist(){
		if ($this->domain_whitelist_included === false){
			if (is_file(realpath('./ext/primehalo/primelinks').'/domain_whitelist.php')){
				include(realpath('./ext/primehalo/primelinks').'/domain_whitelist.php');
			}
			$this->domain_whitelist_included = true;
		}
	}

	/**
	* Removes an attribute from an HTML tag.
	*/
	function remove_attribute($attr_name, $html_tag)
	{
		$html_tag = preg_replace('/\s+' . $attr_name . '="[^"]*"/i', '', $html_tag);
		return $html_tag;
	}

	/**
	* Insert an attribute into an HTML tag.
	*/
	function insert_attribute($attr_name, $new_attr, $html_tag, $overwrite = false)
	{
		$javascript	= (strpos($attr_name, 'on') === 0);	// onclick, onmouseup, onload, etc.
		$old_attr	= preg_replace('/^.*' . $attr_name . '="([^"]*)".*$/i', '$1', $html_tag);
		$is_attr	= !($old_attr == $html_tag);		// Does the attribute already exist?
		$old_attr	= ($is_attr) ? $old_attr : '';

		if ($javascript)
		{
			if ($is_attr && !$overwrite)
			{
				$old_attr = ($old_attr && ($last_char = substr(trim($old_attr), -1)) && $last_char != '}' && $last_char != ';') ? $old_attr . ';' : $old_attr; // Ensure we can add code after any existing code
				$new_attr = $old_attr . $new_attr;
			}
			$overwrite = true;
		}

		if ($overwrite && is_string($overwrite))
		{
			if (strpos(' ' . $overwrite . ' ', ' ' . $old_attr . ' ') !== false)
			{
				// Overwrite the specified value if it exists, otherwise just append the value.
				$new_attr = trim(str_replace(' '  . $overwrite . ' ', ' ' . $new_attr . ' ', ' '  . $old_attr . ' '));
			}
			else
			{
				$overwrite = false;
			}
		}
		if (!$overwrite)
		{
			 // Append the new one if it's not already there.
			$new_attr = strpos(' ' . $old_attr . ' ', ' ' . $new_attr . ' ') === false ? trim($old_attr . ' ' . $new_attr) : $old_attr;
		}

		$html_tag = $is_attr ? str_replace("$attr_name=\"$old_attr\"", "$attr_name=\"$new_attr\"", $html_tag) : str_replace('>', " $attr_name=\"$new_attr\">", $html_tag);
		return($html_tag);
	}

	/**
	* Modify links within a block of text.
	*/
	function modify_links($message = '')
	{
		// A quick check before we start using regular expressions
		if (strpos($message, '<a ') === false)
		{
			return($message);
		}

		preg_match_all('#(<a\s[^>]+?>)(.*?</a>)#i', $message, $matches, PREG_SET_ORDER);
		foreach ($matches as $links)
		{
			$link = $new_link = $links[1];
			$href = preg_replace('/^.*href="([^"]*)".*$/i', '$1', $link);
			if ($href == $link) //no link was found
			{
				continue;
			}
			$href	= $this->decode_entities($href);
			$scheme	= substr($href, 0, strpos($href, ':'));
			if ($scheme)
			{
				$scheme = strtolower($scheme);
				if ($scheme != 'http' && $scheme != 'https') // Only classify links for these schemes (or no scheme)
				{
					continue;
				}
			}
			$external_prefix = $this->external_link_prefix;

			if ($this->skip_link_types && preg_match('/\.(?:' . $this->skip_link_types . ')(?:[#?]|$)/', $href))
			{
				continue;
			}

			$is_local = null;
			$is_local = ($this->internal_link_types && preg_match('/\.(?:' . $this->internal_link_types . ')(?:[#?]|$)/', $href)) ? true : $is_local;
			$is_local = ($this->external_link_types && preg_match('/\.(?:' . $this->external_link_types . ')(?:[#?]|$)/', $href)) ? false : $is_local;
			if ($is_local === null)
			{
				if ($this->forbidden_domains && $this->match_domain($href, $this->forbidden_domains))
				{
					$searches[]		= $link;
					$replacements[]	= $this->insert_attribute('href', $this->forbidden_new_url, $new_link, true);
					continue;
				}
				$is_local = $this->is_link_local($href);
			}
			$new_class	= $is_local ? $this->internal_link_class : $this->external_link_class;
			$new_target	= $is_local ? $this->internal_link_target : $this->external_link_target;
			$new_rel	= $is_local ? $this->internal_link_rel : $this->external_link_rel;

			// Check if this link needs a special class based on the type of file to which it points.
			foreach ($this->file_type_classes as $extensions => $class)
			{
				if ($class && $extensions && preg_match('/\.(?:' . $extensions . ')(?:[#?]|$)/', $href))
				{
					$new_class .= ' ' . $class;
					break;
				}
			}
			if ($new_class)
			{
				$new_link = $this->insert_attribute('class', $new_class, $new_link, 'postlink');
			}
			if ($new_rel)
			{
				$new_link = $this->insert_attribute('rel', $new_rel, $new_link);
			}
			if ($new_target)
			{
				if ($this->use_target_attribute === true)
				{
					$new_link = $this->insert_attribute('target', $new_target, $new_link, true);
				}
				else
				{
					$new_link = $this->insert_attribute('onclick', "this.target='$new_target';", $new_link);
				}
			}
			// Remove the link?
			$is_guest = empty($this->user->data['is_registered']);
			if ($new_target === false || ($is_guest && $this->external_hide_guests && !$is_local) || ($is_guest && $this->internal_hide_guests && $is_local))
			{
				$new_text = substr($links[2], 0, -4);
				$new_text = ($is_guest && is_string($this->external_hide_guests) && !$is_local) ? $this->external_hide_guests : $new_text;
				$new_text = ($is_guest && is_string($this->internal_hide_guests) && $is_local) ? $this->internal_hide_guests : $new_text;
				$new_link = '<span class="link_removed">' . $new_text . '</span>';
				$link = $links[0];
			}
			else if (!$is_local && $external_prefix)
			{
				$external_prefix = ($this->skip_prefix_types && preg_match('/\.(?:' . $this->skip_prefix_types . ')(?:[#?]|$)/', $href)) ? '' : $external_prefix;
				$new_link = str_replace('href="', 'href="' . $external_prefix, $new_link);
			}
			$searches[]		= $link;
			$replacements[]	= $new_link;
		}
		if (isset($searches) && isset($replacements))
		{
			$message = str_replace($searches, $replacements, $message);
		}
		return($message);
	}
}