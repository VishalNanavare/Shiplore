<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* server/variables/index.twig */
class __TwigTemplate_00a70ebc9ad0423d2777d3c0f7a42d03 extends Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        yield "<div class=\"container-fluid\">
<div class=\"row\">
  <h2>
    ";
        // line 4
        yield PhpMyAdmin\Html\Generator::getImage("s_vars");
        yield "
    ";
yield _gettext("Server variables and settings");
        // line 6
        yield "    ";
        yield PhpMyAdmin\Html\MySQLDocumentation::show("server_system_variables");
        yield "
  </h2>
</div>

";
        // line 10
        if ( !Twig\Extension\CoreExtension::testEmpty(($context["variables"] ?? null))) {
            // line 11
            yield "  <a href=\"#\" class=\"ajax saveLink hide\">
    ";
            // line 12
            yield PhpMyAdmin\Html\Generator::getIcon("b_save", _gettext("Save"));
            yield "
  </a>
  <a href=\"#\" class=\"cancelLink hide\">
    ";
            // line 15
            yield PhpMyAdmin\Html\Generator::getIcon("b_close", _gettext("Cancel"));
            yield "
  </a>
  ";
            // line 17
            yield PhpMyAdmin\Html\Generator::getImage("b_help", _gettext("Documentation"), ["class" => "hide", "id" => "docImage"]);
            // line 20
            yield "

  ";
            // line 22
            yield from             $this->loadTemplate("filter.twig", "server/variables/index.twig", 22)->unwrap()->yield(CoreExtension::toArray(["filter_value" =>             // line 23
($context["filter_value"] ?? null)]));
            // line 25
            yield "
  <div class=\"table-responsive\">
    <table id=\"serverVariables\" class=\"table table-striped table-hover table-sm\">
      <thead>
        <tr>
          <th scope=\"col\">";
yield _gettext("Action");
            // line 30
            yield "</th>
          <th scope=\"col\">";
yield _gettext("Variable");
            // line 31
            yield "</th>
          <th scope=\"col\" class=\"text-end\">";
yield _gettext("Value");
            // line 32
            yield "</th>
        </tr>
      </thead>

      <tbody>
        ";
            // line 37
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["variables"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["variable"]) {
                // line 38
                yield "          <tr class=\"var-row\" data-filter-row=\"";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::upper($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 38)), "html", null, true);
                yield "\">
            <td>
              ";
                // line 40
                if (CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "is_editable", [], "any", false, false, false, 40)) {
                    // line 41
                    yield "                <a href=\"#\" data-variable=\"";
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 41), "html", null, true);
                    yield "\" class=\"editLink\">";
                    yield PhpMyAdmin\Html\Generator::getIcon("b_edit", _gettext("Edit"));
                    yield "</a>
              ";
                } else {
                    // line 43
                    yield "                <span title=\"";
yield _gettext("This is a read-only variable and can not be edited");
                    yield "\" class=\"read_only_var\">
                  ";
                    // line 44
                    yield PhpMyAdmin\Html\Generator::getIcon("bd_edit", _gettext("Edit"));
                    yield "
                </span>
              ";
                }
                // line 47
                yield "            </td>
            <td class=\"var-name fw-bold\">
              ";
                // line 49
                if ((CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "doc_link", [], "any", false, false, false, 49) != null)) {
                    // line 50
                    yield "                <span title=\"";
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::replace(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 50), ["_" => " "]), "html", null, true);
                    yield "\">
                  ";
                    // line 51
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "doc_link", [], "any", false, false, false, 51);
                    yield "
                </span>
              ";
                } else {
                    // line 54
                    yield "                ";
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::replace(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 54), ["_" => " "]), "html", null, true);
                    yield "
              ";
                }
                // line 56
                yield "            </td>
            <td class=\"var-value text-end font-monospace";
                // line 57
                yield ((($context["is_superuser"] ?? null)) ? (" editable") : (""));
                yield "\">
              ";
                // line 58
                if (CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "is_escaped", [], "any", false, false, false, 58)) {
                    // line 59
                    yield "                ";
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "value", [], "any", false, false, false, 59);
                    yield "
              ";
                } else {
                    // line 61
                    yield "                ";
                    yield Twig\Extension\CoreExtension::replace($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "value", [], "any", false, false, false, 61)), ["," => ",&#8203;"]);
                    yield "
              ";
                }
                // line 63
                yield "            </td>
          </tr>

          ";
                // line 66
                if (CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "has_session_value", [], "any", false, false, false, 66)) {
                    // line 67
                    yield "            <tr class=\"var-row\" data-filter-row=\"";
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::upper($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 67)), "html", null, true);
                    yield "\">
              <td></td>
              <td class=\"var-name font-italic\">";
                    // line 69
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::replace(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "name", [], "any", false, false, false, 69), ["_" => " "]), "html", null, true);
                    yield " (";
yield _gettext("Session value");
                    yield ")</td>
              <td class=\"var-value text-end font-monospace\">";
                    // line 70
                    yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["variable"], "session_value", [], "any", false, false, false, 70), "html", null, true);
                    yield "</td>
            </tr>
          ";
                }
                // line 73
                yield "        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_iterated'], $context['_key'], $context['variable'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 74
            yield "      </tbody>
    </table>
  </div>
</div>

";
        } else {
            // line 80
            yield "  ";
            yield $this->env->getFilter('error')->getCallable()(Twig\Extension\CoreExtension::sprintf(_gettext("Not enough privilege to view server variables and settings. %s"), PhpMyAdmin\Html\Generator::linkToVarDocumentation("show_compatibility_56",             // line 81
($context["is_mariadb"] ?? null))));
            // line 82
            yield "
";
        }
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "server/variables/index.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable()
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo()
    {
        return array (  222 => 82,  220 => 81,  218 => 80,  210 => 74,  204 => 73,  198 => 70,  192 => 69,  186 => 67,  184 => 66,  179 => 63,  173 => 61,  167 => 59,  165 => 58,  161 => 57,  158 => 56,  152 => 54,  146 => 51,  141 => 50,  139 => 49,  135 => 47,  129 => 44,  124 => 43,  116 => 41,  114 => 40,  108 => 38,  104 => 37,  97 => 32,  93 => 31,  89 => 30,  81 => 25,  79 => 23,  78 => 22,  74 => 20,  72 => 17,  67 => 15,  61 => 12,  58 => 11,  56 => 10,  48 => 6,  43 => 4,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "server/variables/index.twig", "/opt/homebrew/var/www/pma/templates/server/variables/index.twig");
    }
}
